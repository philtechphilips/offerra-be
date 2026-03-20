# 🛡️ Offerra Backend: The Core Engine

The Offerra Backend is a robust, enterprise-grade API built with **Laravel 12 (PHP 8.3+)**. It handles all critical business logic, from job tracking to secure payment processing.

![Backend Architecture](https://via.placeholder.com/1200x500?text=Laravel+Backend+Architecture)

## 🧩 Module Breakdown

### 1. 💳 Payment & Webhook Intelligence
*   **Provider Interfacing**: Native support for **Paystack** and **Polar (Stripe)**.
*   **Atomic Transactions (`DB::transaction`)**: Every webhook execution is wrapped in a transaction. If any part fails (e.g., mail sending or notification dispatch), the credits are automatically rolled back, ensuring zero accidental loss for the platform.
*   **Idempotency Engine**: Uses `Cache::put` with short TTLs to prevent processing the same payment reference twice during simultaneous provider retries.
*   **Credit Logs (`credit_logs`)**: Centralized ledger for all credit movements (bonus, purchase, or consumption).

### 2. 🔔 Notification System (`notifications`)
*   **Polymorphic Database Channel**: Uses Laravel’s `notifiable_id` and `notifiable_type` with **UUIDs** to link alerts to any model.
*   **Generic Notification Class**: A unified `GenericNotification` class that handles titles, messages, and action URLs across the dashboard.
*   **Trigger Events**: 
    *   **Welcome**: Sent on registration.
    *   **Payment Success**: Triggered immediately after webhook confirmation.
    *   **CV Processor**: Triggered when AI processing completes.

### 3. 🛡️ Admin & Control logic
*   **Revenue Engine**: Aggregated SQL queries for Sales Velocity, Monthly Revenue, and Popular Plans.
*   **Manual Credit Overrides**: Secure endpoints to manually adjust user balances for support/bonus purposes.
*   **Role Middleware**: Custom `role:admin` middleware to protect management endpoints.

---

## 🏗️ Technical Architecture

*   **UUID Primary Keys**: Every database table uses `uuid()` as its ID for security and obfuscation.
*   **Eloquent Relationships**: 
    *   `User` ↔ `JobApplication` (HasMany)
    *   `User` ↔ `Transaction` (HasMany)
    *   `Plan` ↔ `Transaction` (HasMany)
*   **Sanctum Authentication**: Token-based security for both the Frontend and Chrome Extension.

---

## 🚀 Setup & Installation

1.  **Clone & Install**:
    ```bash
    composer install
    ```
2.  **Environment Configuration**:
    Copy `.env.example` to `.env` and configure:
    *   `DB_DATABASE`, `PAYSTACK_SECRET`, `POLAR_API_KEY`, `FRONTEND_URL`.
3.  **Database Migration**:
    ```bash
    php artisan migrate:fresh --seed
    ```
4.  **Running the Server**:
    ```bash
    php artisan serve
    ```

---

## 📸 Backend UI & Logs

| Dashboard Logs | Payment Receipt Email | 
| :---: | :---: |
| ![Logs Screenshot](https://via.placeholder.com/400x250?text=Laravel+Error+Logs) | ![Receipt Screenshot](https://via.placeholder.com/400x250?text=Branded+Receipt+Email) |

---

## 📄 License
Copyright © 2026 Offerra Backend.
