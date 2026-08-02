require("dotenv").config();
const express = require("express");
const mysql = require("mysql2");
const helmet = require("helmet");

const app = express();
const PORT = process.env.PORT || 3000;

app.use(helmet());
app.use(express.json());

const db = mysql.createPool({
    host: process.env.DB_HOST,
    user: process.env.DB_USER,
    password: process.env.DB_PASSWORD,
    database: process.env.DB_NAME,
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

// LEDGER 3: New Order Entry Processing Tunnel for the Mobile App!
app.post("/api/orders", (req, res) => {
    const { user_id, plan_id, total_amount } = req.body;

    if (!user_id || !plan_id || !total_amount) {
        return res.status(400).json({ success: false, error: "Validation Failure: Missing user_id, plan_id, or total_amount ledger fields." });
    }

    const queryStr = "INSERT INTO orders (user_id, plan_id, total_amount, status, created_at) VALUES (?, ?, ?, 'pending', NOW())";
    db.query(queryStr, [user_id, plan_id, total_amount], (err, result) => {
        if (err) return res.status(500).json({ error: err.message });
        
        res.status(201).json({
            success: true,
            message: "Transaction Journal posted successfully.",
            order_id: result.insertId,
            audit_trail: {
                buyer_id: user_id,
                item_purchased: plan_id,
                amount_billed: total_amount,
                reconciliation: "Pending verification"
            }
        });
    });
});

app.listen(PORT, () => {
    console.log("🔒 Secured System active on http://localhost:" + PORT);
});
