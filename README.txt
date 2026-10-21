JV HARDWARE INVENTORY AND SALES MANAGEMENT SYSTEM
Brgy. Lomboy, Inabanga, Bohol
=====================================================

HOW TO RUN (3 steps, no importing needed)
1. Copy this whole folder to:  C:\xampp\htdocs\JV_Hardware_Inventory_System
2. Open the XAMPP Control Panel and click START beside Apache and MySQL.
3. Open your browser and go to:  http://localhost/JV_Hardware_Inventory_System/

The first time you open it, the system creates the database, the tables,
sample products and the login accounts BY ITSELF. You do not need phpMyAdmin.
(If you already used the older version, your products and sales are kept and
upgraded automatically.)

LOGIN
  Owner :  username  owner   password  admin123
  Staff :  username  staff   password  staff123
Change the passwords in  Settings  after your first login.

WHAT THE SYSTEM DOES  (matches the proposal)
  Product database ........ Products page: add / edit / search / archive, or import a whole price list from CSV
  Price management ........ Edit a price on the Products page; every change is logged (Reports > Price changes)
  Automatic stock updates . Record Sale subtracts stock; Stock In adds stock; every change is saved in Stock history
  Low-stock alerts ........ Dashboard alerts, red badge on the menu, and a ready-to-print Reorder list
  Sales reports ........... Reports: today / week / month / custom dates, top products, payment methods, CSV and Print
  Receipts ................ Printable receipt after every sale; owner can void a sale (stock is returned)

WHO CAN DO WHAT
  Owner .... everything, including staff accounts, stock out, count corrections, voiding sales, archiving products
  Staff .... record sales, stock in, view reports
  Staff with "Can edit products & prices" ticked by the owner can also add products and change prices

DAY-TO-DAY USE
  Record Sale ...... tap products (or type / scan the code and press Enter), type the cash received, press Complete sale
  Stock In / Out ... use when a delivery arrives. "Correct count" fixes the system when a shelf count is different.
  Dashboard ........ check "Restock alerts" every morning

FOR YOUR PRESENTATION
  Settings > Demo data > "Add sample sales" fills the dashboard and reports with 30 days of sample sales.
  Use "Remove sample sales" before using the system for real.

FOLDER GUIDE
  index.php  products.php  sales.php  receipt.php  stock.php  reports.php  settings.php  login.php
  includes/  database connection + shared code     assets/  style.css, app.js, pos.js
  database.sql  table definitions (optional, for reference)

IF YOU SET A MYSQL PASSWORD
  Open includes/config.php and type the password in DB_PASS.

IMPORTANT NOTES
  - This is a local system for the store computer. Do not put it on the public internet as-is.
  - Sample products and prices are placeholders; replace them with the store's real list
    (Products > Import CSV makes this quick).
  - Back up the database sometimes: phpMyAdmin > jv_hardware_db > Export.
  - Products with sales history are ARCHIVED instead of deleted so old reports stay correct.
  - Requires PHP 7.4 or newer (the XAMPP default is fine).
