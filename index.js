require("dotenv").config();
const express = require("express");
const mysql = require("mysql2");
const helmet = require("helmet");

const app = express();
const PORT = process.env.PORT || 3000;

app.use(helmet());
app.use(express.json());

// RECONCILED PRODUCTION POOL: Matching your live PHP configuration settings!
const db = mysql.createPool({
    host: process.env.DB_HOST || "localhost",
    user: process.env.DB_USER || "root",          
    password: process.env.DB_PASS || process.env.DB_PASSWORD || "",          
    database: process.env.DB_NAME || "mbista_altiora_complete_hosting",    
    waitForConnections: true,
    connectionLimit: 10,
    queueLimit: 0
});

// Landing Page Welcome Gate
app.get("/", (req, res) => {
    res.send("<h1>🔒 Secure M.Bista API Infrastructure Active</h1><p>Operational data extraction tunnels are active. Endpoints: <code>/api/users</code>, <code>/api/plans</code>, and <code>/api/orders</code></p>");
});

// LEDGER 1: Active Users Directory
app.get("/api/users", (req, res) => {
    db.query("SELECT id, email, role FROM users", (err, results) => {
        if (err) return res.status(500).json({ error: err.message });
        res.json({ success: true, count: results.length, data: results });
    });
});

// LEDGER 2: Storefront Product Directory
app.get("/api/plans", (req, res) => {
    db.query("SELECT id, name, price, billing_cycle, disk_space_gb, bandwidth_gb, features FROM plans WHERE is_active = 1", (err, results) => {
        if (err) return res.status(500).json({ error: err.message });
        res.json({ success: true, count: results.length, packages: results });
    });
});

app.listen(PORT, () => {
    console.log("🔒 Secured System active.");
});