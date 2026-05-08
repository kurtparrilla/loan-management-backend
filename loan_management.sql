CREATE TABLE users (
    user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
)ENGINE=InnoDB;

CREATE TABLE borrowers (
    borrower_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(255) NOT NULL,
    last_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NULL UNIQUE,
    phone VARCHAR(20) NULL,
    address VARCHAR(255) NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_borrower_user
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL
)ENGINE=InnoDB;

CREATE TABLE loans(
    loan_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    borrower_id INT UNSIGNED NOT NULL,
    principal_amount DECIMAL(15,2) NOT NULL,
    interest_rate DECIMAL(5,3) NOT NULL DEFAULT 0,
    payment_frequency ENUM('weekly','biweekly','monthly','quarterly','yearly','lump_sum') NOT NULL,
    number_of_installments SMALLINT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    status ENUM('active','paid','defaulted','cancelled') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_loans_borrower
        FOREIGN KEY (borrower_id)
        REFERENCES borrowers(borrower_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE loan_schedules(
    schedule_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    loan_id INT UNSIGNED NOT NULL,
    installment_number SMALLINT UNSIGNED NOT NULL,
    due_date DATE NOT NULL,
    expected_principal DECIMAL(15,2) NOT NULL,
    expected_interest DECIMAL(15,2) NOT NULL,
    expected_total DECIMAL(15,2)
        GENERATED ALWAYS AS (expected_principal + expected_interest) STORED,
    status ENUM('pending','partially_paid','paid','overdue') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_schedule_loan
        FOREIGN KEY (loan_id)
        REFERENCES loans(loan_id)
        ON DELETE CASCADE,

    UNIQUE KEY uq_loan_installment (loan_id, installment_number)
) ENGINE=InnoDB;

CREATE TABLE payments(
    payment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    loan_id INT UNSIGNED NOT NULL,
    amount_paid DECIMAL(15,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method VARCHAR(50) NULL,
    reference_number VARCHAR(100) NULL,
    remaining_balance DECIMAL(15,2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_payment_loan
        FOREIGN KEY (loan_id)
        REFERENCES loans(loan_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE payment_allocations (
    allocation_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id      INT UNSIGNED NOT NULL,
    schedule_id     INT UNSIGNED NOT NULL,
    amount_applied  DECIMAL(15,2) NOT NULL,
    CONSTRAINT fk_alloc_payment 
        FOREIGN KEY (payment_id) 
        REFERENCES payments(payment_id) 
        ON DELETE CASCADE,
    CONSTRAINT fk_alloc_schedule 
        FOREIGN KEY (schedule_id) 
        REFERENCES loan_schedules(schedule_id) 
        ON DELETE RESTRICT,
    UNIQUE KEY uq_payment_schedule (payment_id, schedule_id)
) ENGINE=InnoDB;

CREATE TABLE auth_tokens (
    token_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    token       VARCHAR(255) NOT NULL UNIQUE,
    expires_at  DATETIME NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_token_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE INDEX idx_loans_status ON loans(status);
CREATE INDEX idx_schedules_due_status ON loan_schedules(due_date, status);
CREATE INDEX idx_payments_date ON payments(payment_date);
CREATE INDEX idx_allocations_payment ON payment_allocations(payment_id);
CREATE INDEX idx_allocations_schedule ON payment_allocations(schedule_id);