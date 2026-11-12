# Barangay Health Center Appointment Management and Supply Forecasting System

A web-based system for the health centers of Malabon District II that lets patients book, reschedule, and cancel appointments online, and lets health center staff and doctors manage consultations, prescriptions, and medicine stock. It forecasts how long current medicine supplies will last using linear regression on prescription history, so staff can restock before shortages occur. The main objectives are to reduce long queues and missed visits, give patients a clear booking process, and give administrators district-wide visibility into services and supplies.

---

## Group Members & Roles
* **[Agundis, Ronellle Jane]** – Project Manager / Lead Developer
* **[Buitizon, Gilbert]** - QA Tester
* **[Co, Earl Vincent]** - Full Stack Developer
* **[Cordero, Justin]** - QA Tester
* **[Jayan, Rodolfo III]** - QA Tester

---

## Tech Stack
* **Frontend:** HTML, CSS, JavaScript
* **Backend:** PHP
* **Database:** MySQL
* **Tools & Version Control:** Git, VS Code, XAMPP

---

## System Requirements & Prerequisites
Before you begin, ensure you have the following installed on your local machine:
* PHP (v8.0+)
* XAMPP (includes Apache and MySQL Server)
* A modern web browser (Chrome, Edge, or Firefox)

---

## Installation & Setup Instructions

Follow these step-by-step commands to set up the project locally:

### 1. Clone the Repository
```bash
git clone https://github.com/WoofWoofSlayer123/An-AI-Based-Appointment-Management-System-with-Supply-Forecasting-System.git
cd your-repo-name
```

### 2. Move the Project into XAMPP
Copy the project folder into the `htdocs` directory of your XAMPP installation (for example, `C:\xampp\htdocs\malabon-health-center`).

---

## Database Setup & Configuration

Follow these steps to configure your local database:

1. **Start your local database server** (open XAMPP and start **Apache** and **MySQL**).
2. **Create a new database:**
   * Open phpMyAdmin (`http://localhost/phpmyadmin`) or your database client.
   * Create a new database named: `db_malabon_health_center` (match this with your database configuration file).
3. **Import the Database Schema:**
   * Locate the SQL export file inside the repository folder (usually found under `/database/db_backup.sql` or `/sql/schema.sql`).
   * Import this file into your newly created database.
4. **Configure the Database Connection:**
   * Open the database configuration file (for example, `config/connection.php`).
   * Update the connection settings:
     ```php
      $servername = "localhost";
      $username = "root";
      $password = "123456789";
      $dbname = "health_center_system";
     ```

---

##  Running the Application

Open your browser and navigate to: `http://localhost/malabon-health-center` (or your respective local folder name).

To use the system, log in through the landing page and choose the account type for your role (patient, staff, doctor, or admin).

---
