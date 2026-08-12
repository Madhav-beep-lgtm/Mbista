// The path is explicit because cPanel's Passenger starts the app with a working
// directory that is not necessarily this one. dotenv's default reads
// process.cwd()/.env, so under Passenger it silently found nothing and every
// setting fell back to its default — including the database name.
require("dotenv").config({ path: require("path").join(__dirname, ".env") });
const express = require("express");
const mysql = require("mysql2");
const helmet = require("helmet");
const bcrypt = require("bcryptjs");
const crypto = require("crypto");

const app = express();

/*
 * RUNNING UNDER PASSENGER (cPanel "Setup Node.js App")
 * ---------------------------------------------------
 * Passenger hands the application its listening address in PORT, and on cPanel
 * that is frequently a UNIX SOCKET PATH rather than a number. app.listen(port,
 * host) with a path in `port` does not throw — it quietly listens somewhere
 * nobody is connecting to, and the app looks up while every request 502s.
 *
 * So: a numeric PORT is a port and gets the bind address; anything else is a
 * path and is passed alone, because a socket has no host.
 */
const RAW_PORT = process.env.PORT || 3000;
const PORT_IS_SOCKET = !/^\d+$/.test(String(RAW_PORT));
const PORT = PORT_IS_SOCKET ? String(RAW_PORT) : Number(RAW_PORT);
// 127.0.0.1, not 0.0.0.0. Binding every interface is how a "local" API quietly
// starts answering the whole network; the default should be the safe one, and
// opening it up should be a decision somebody typed. Under Passenger, Apache is
// the only thing that reaches it anyway.
const BIND = process.env.API_BIND || "127.0.0.1";

/*
 * BEHIND APACHE, EVERY REQUEST LOOKS LIKE IT CAME FROM APACHE.
 *
 * Without this, req.ip is the proxy's address for every caller alive — so the
 * login throttle, which is keyed on it, would count the whole world's failures
 * into one bucket and lock EVERYBODY out after five wrong passwords. A
 * brute-force guard that turns into a denial of service on the first attack is
 * worse than no guard.
 *
 * One hop by default: Apache. Trusting more than actually sits in front lets a
 * caller forge X-Forwarded-For and hand itself a fresh bucket per attempt.
 */
app.set("trust proxy", Number(process.env.API_TRUST_PROXY || 1));

app.use(helmet());
app.use(express.json({ limit: "100kb" }));

// RECONCILED PRODUCTION POOL: matching the live PHP configuration.
const db = mysql.createPool({
    host: process.env.DB_HOST || "localhost",
    user: process.env.DB_USER || "root",
    password: process.env.DB_PASS || process.env.DB_PASSWORD || "",
    database: process.env.DB_NAME || "mbista_altiora_complete_hosting",
    waitForConnections: true,
    connectionLimit: Math.max(1, Number(process.env.DB_CONNECTION_LIMIT || 10)),
    // A bounded queue prevents an overloaded database from turning into an
    // ever-growing process-memory backlog. Tune both values with load tests.
    queueLimit: Math.max(1, Number(process.env.DB_QUEUE_LIMIT || 100)),
    // Several statements in one string is how one injected parameter becomes a
    // second statement. Off, and said out loud.
    multipleStatements: false
}).promise();

// ---------------------------------------------------------------------------
// WHY THERE IS A LOCK ON THIS DOOR
// ---------------------------------------------------------------------------
// helmet() sets response headers. It does NOT ask who is calling — so with it
// alone, `GET /api/users` handed every user's id, email and role to anyone who
// could reach the port.
//
// And this database holds SEVERAL CLIENTS' BOOKS side by side. Every table that
// matters carries company_id, and a query that forgets it does not fail: it
// succeeds, and hands one client's figures to another. So an open endpoint here
// is not "a bit of data" — it is every user of every company on the server.
//
// The shape: log in, get a token, and the TOKEN decides which company may be
// read. Never a query parameter — a client that picks its own company_id is a
// client that reads everybody's.
const JWT_SECRET = process.env.JWT_SECRET || "";
if (!JWT_SECRET || JWT_SECRET.length < 24) {
    console.error(
        "Refusing to start: JWT_SECRET must be at least 24 characters, set in .env\n" +
        "Generate one with:\n" +
        "  node -e \"console.log(require('crypto').randomBytes(32).toString('hex'))\"\n\n" +
        "It exits rather than run without auth: \"the variable was not set\" is exactly how\n" +
        "an unauthenticated API reaches production believing itself protected."
    );
    process.exit(1);
}
const TOKEN_TTL_SECONDS = Number(process.env.TOKEN_TTL_SECONDS || 8 * 60 * 60);

const b64url = (buf) => Buffer.from(buf).toString("base64")
    .replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");

/** HS256 with Node's own crypto — a JWT is small enough not to be worth a dependency. */
function signToken(payload) {
    const header = b64url(JSON.stringify({ alg: "HS256", typ: "JWT" }));
    const now = Math.floor(Date.now() / 1000);
    const body = b64url(JSON.stringify({ ...payload, iat: now, exp: now + TOKEN_TTL_SECONDS }));
    const sig = b64url(crypto.createHmac("sha256", JWT_SECRET).update(header + "." + body).digest());

    return header + "." + body + "." + sig;
}

function verifyToken(token) {
    const parts = String(token || "").split(".");
    if (parts.length !== 3) {
        return null;
    }
    const [header, body, sig] = parts;
    const expected = b64url(crypto.createHmac("sha256", JWT_SECRET).update(header + "." + body).digest());
    // Constant time: comparing signatures with === leaks them a byte at a time.
    const a = Buffer.from(sig);
    const b = Buffer.from(expected);
    if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) {
        return null;
    }
    let claims;
    try {
        claims = JSON.parse(Buffer.from(body.replace(/-/g, "+").replace(/_/g, "/"), "base64").toString());
    } catch (err) {
        return null;
    }
    if (!claims.exp || claims.exp < Math.floor(Date.now() / 1000)) {
        return null;
    }

    return claims;
}

/**
 * CORS is OFF unless somebody names the origins.
 *
 * A Capacitor build, or any web client on another domain, is refused by the
 * browser without these headers — and the fix usually pasted from the internet
 * is `*`, which invites every site the user has open to call this API with
 * their token. So: a list, from the environment, and nothing by default.
 *
 *   API_CORS_ORIGINS=https://app.example.com,https://admin.example.com
 */
const CORS_ORIGINS = String(process.env.API_CORS_ORIGINS || "")
    .split(",").map((s) => s.trim()).filter(Boolean);
app.use((req, res, next) => {
    const origin = req.get("origin");
    const allowed = Boolean(origin) && CORS_ORIGINS.includes(origin);
    if (allowed) {
        res.set("Access-Control-Allow-Origin", origin);
        res.set("Vary", "Origin");
        res.set("Access-Control-Allow-Headers", "Authorization, Content-Type");
        res.set("Access-Control-Allow-Methods", "GET, POST, OPTIONS");
    }
    if (req.method === "OPTIONS") {
        return res.sendStatus(allowed ? 204 : 403);
    }

    return next();
});

// A password guess costs bcrypt time, which is the point — but a script does not
// mind waiting. Five misses per address per fifteen minutes; a success clears
// the count. In memory, so a restart forgives everyone: enough to stop a script,
// and it is not meant to be the last line of defence.
const attempts = new Map();
function tooManyAttempts(key) {
    const rec = attempts.get(key);

    return Boolean(rec && Date.now() <= rec.until && rec.count >= 5);
}
function noteFailure(key) {
    const now = Date.now();
    const rec = attempts.get(key);
    if (!rec || now > rec.until) {
        attempts.set(key, { count: 1, until: now + 15 * 60 * 1000 });
    } else {
        rec.count += 1;
    }
}

/** Turns a thrown error into a 500 without handing the caller the SQL. */
function wrap(handler) {
    return async (req, res) => {
        try {
            await handler(req, res);
        } catch (err) {
            // The message goes to the log, never to the caller: a SQL error
            // names tables, columns, and sometimes the value that broke it.
            console.error("[" + req.method + " " + req.path + "]", err.message);
            if (!res.headersSent) {
                res.status(500).json({ success: false, error: "Request failed — see the server log." });
            }
        }
    };
}

// Landing page
app.get("/", (req, res) => {
    res.type("html").send(
        "<h1>M.Bista API</h1><p>Read-only. POST <code>/api/auth/login</code> for a token, " +
        "then send <code>Authorization: Bearer &lt;token&gt;</code>.</p>"
    );
});

/*
 * Health check — the one route that answers without a token.
 *
 * It exists to tell a blank page apart from a broken one. Hitting the API and
 * getting an empty 200 back says nothing: Passenger not started, the subdomain
 * pointed at an empty directory, and a crashed worker all look identical from
 * a browser. Any JSON at all from here proves Node is running and reached; the
 * db field then separates "the app is up" from "the app is up and can read".
 *
 * Deliberately unauthenticated, because a health check that needs a login
 * cannot tell you the login is broken. Equally deliberately it says nothing
 * else: no version, no hostname, no database name, no error text. Those are
 * free reconnaissance on a public endpoint, and the reason a query failed
 * belongs in the log where it already goes.
 *
 * 503 rather than 200 when the database is unreachable, so an uptime monitor
 * treats it as down without having to parse the body.
 */
app.get("/api/health", async (req, res) => {
    let database = "up";
    try {
        await db.query("SELECT 1");
    } catch (err) {
        console.error("[GET /api/health]", err.message);
        database = "down";
    }
    res.status(database === "up" ? 200 : 503).json({
        status: database === "up" ? "ok" : "degraded",
        database: database,
        time: new Date().toISOString()
    });
});

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------
app.post("/api/auth/login", wrap(async (req, res) => {
    const email = String((req.body && req.body.email) || "").trim().toLowerCase();
    const password = String((req.body && req.body.password) || "");
    const throttleKey = (req.ip || "unknown") + "|" + email;
    if (tooManyAttempts(throttleKey)) {
        return res.status(429).json({ success: false, error: "Too many attempts. Try again later." });
    }
    if (email === "" || password === "") {
        return res.status(400).json({ success: false, error: "Email and password are required." });
    }

    const [rows] = await db.query(
        "SELECT id, name, email, role, status, company_id, password_hash FROM users WHERE email = ? LIMIT 1",
        [email]
    );
    const user = rows[0];
    // PHP writes these with password_hash(), which is bcrypt — $2y$. bcryptjs
    // reads $2y$ once normalised to $2a$, so the same hash verifies in both
    // languages and a password is still SET in exactly one place.
    const ok = Boolean(user) && Boolean(user.password_hash)
        && await bcrypt.compare(password, String(user.password_hash).replace(/^\$2y\$/, "$2a$"));
    // ONE message for "no such user" and for "wrong password". Telling them
    // apart is how an attacker enumerates who banks here.
    if (!ok || String(user.status) !== "active") {
        noteFailure(throttleKey);

        return res.status(401).json({ success: false, error: "Invalid credentials." });
    }
    attempts.delete(throttleKey);

    res.json({
        success: true,
        token: signToken({ sub: user.id, role: user.role, company_id: user.company_id }),
        expires_in: TOKEN_TTL_SECONDS,
        user: { id: user.id, name: user.name, email: user.email, role: user.role, company_id: user.company_id }
    });
}));

// ---------------------------------------------------------------------------
// Everything past here needs a token, and reads only
// ---------------------------------------------------------------------------

/**
 * Read-only, enforced HERE rather than by simply not writing any write routes.
 * The books are written by the PHP application and by nothing else: every rule
 * that makes a figure correct — company scoping, fiscal-year locks, the
 * settlement identity — lives there, and a second stack that could write would
 * be a second implementation of all of it, disagreeing quietly. The next person
 * to add a POST has to delete this and think about why.
 */
app.use("/api", (req, res, next) => {
    if (req.method !== "GET" && req.method !== "HEAD") {
        return res.status(405).json({
            success: false,
            error: "This API is read-only. The books are written by the application."
        });
    }

    return next();
});

app.use("/api", (req, res, next) => {
    const header = req.get("authorization") || "";
    const claims = header.startsWith("Bearer ") ? verifyToken(header.slice(7)) : null;
    if (!claims) {
        return res.status(401).json({
            success: false,
            error: "Log in at /api/auth/login and send the token as a Bearer header."
        });
    }
    req.user = claims;

    return next();
});

/** The company comes off the TOKEN. There is no parameter for it, on purpose. */
function scope(req) {
    return Number(req.user.company_id) || 0;
}

// LEDGER 1: users of the caller's own company
app.get("/api/users", wrap(async (req, res) => {
    // Columns named one at a time, never SELECT *: password_hash lives in this
    // table, and SELECT * is how it reaches a JSON response the first time
    // somebody adds a column.
    const [rows] = await db.query(
        "SELECT id, name, email, role, status, company_id FROM users WHERE company_id = ? ORDER BY name ASC",
        [scope(req)]
    );
    res.json({ success: true, count: rows.length, data: rows });
}));

// LEDGER 2: the storefront price list. The same list the public site shows
// anyone, so it is not company-scoped — but it stays behind the token because
// there is no reason for it not to be.
app.get("/api/plans", wrap(async (req, res) => {
    const [rows] = await db.query(
        "SELECT id, name, price, billing_cycle, disk_space_gb, bandwidth_gb, features FROM plans WHERE is_active = 1"
    );
    res.json({ success: true, count: rows.length, packages: rows });
}));

// LEDGER 3: the trial balance. Debit-positive per ledger over POSTED vouchers
// only — a draft is not in the books, and counting one is how an API comes to
// disagree with the screen it is meant to mirror.
app.get("/api/trial-balance", wrap(async (req, res) => {
    const asOf = /^\d{4}-\d{2}-\d{2}$/.test(String(req.query.as_of || "")) ? String(req.query.as_of) : null;
    const [rows] = await db.query(
        `SELECT l.id, l.code, l.name, l.type,
                ROUND(SUM(CASE WHEN e.entry_type = 'debit' THEN e.amount ELSE -e.amount END), 2) AS balance
           FROM voucher_entries e
           INNER JOIN vouchers v ON v.id = e.voucher_id
           INNER JOIN ledgers l ON l.id = e.ledger_id
          WHERE v.company_id = ? AND v.status = 'posted'
            AND (? IS NULL OR v.voucher_date <= ?)
          GROUP BY l.id, l.code, l.name, l.type
         HAVING ABS(balance) > 0.004
          ORDER BY l.code ASC`,
        [scope(req), asOf, asOf]
    );
    const total = rows.reduce((sum, row) => sum + Number(row.balance), 0);
    res.json({
        success: true,
        as_of: asOf,
        count: rows.length,
        // Not zero means the books do not balance, and saying so here is more
        // use than a list the reader has to add up to find out.
        balances_to: Math.round(total * 100) / 100,
        data: rows
    });
}));

app.use((req, res) => {
    res.status(404).json({ success: false, error: "No such endpoint." });
});

const onListening = () => {
    console.log(PORT_IS_SOCKET
        ? "Read-only API on socket " + PORT + " (Passenger)"
        : "Read-only API on http://" + BIND + ":" + PORT);
    console.log("  database : " + (process.env.DB_NAME || "mbista_altiora_complete_hosting"));
    console.log("  auth     : POST /api/auth/login  ->  Authorization: Bearer <token>");
    console.log("  cors     : " + (CORS_ORIGINS.length ? CORS_ORIGINS.join(", ") : "off (set API_CORS_ORIGINS to allow a client)"));
    console.log("  routes   : /api/users  /api/plans  /api/trial-balance");
};
// A socket takes no host; a port does. Passing BIND alongside a socket path is
// what makes Node listen on nothing in particular.
const server = PORT_IS_SOCKET
    ? app.listen(PORT, onListening)
    : app.listen(PORT, BIND, onListening);

// Close the pool on the way out, so a restart mid-query does not leave the
// connection sitting on the MySQL server until it times out by itself.
for (const signal of ["SIGINT", "SIGTERM"]) {
    process.on(signal, () => {
        console.log("\nShutting down…");
        server.close(() => {
            db.end().then(() => process.exit(0)).catch(() => process.exit(0));
        });
    });
}
