-- Chitty Platform schema (MySQL / MariaDB)
-- Import with: mysql -u youruser -p yourdbname < sql/schema.sql

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(20) NOT NULL UNIQUE,
  email VARCHAR(150) NULL,
  address VARCHAR(255) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','member') NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A chit scheme run by the society, e.g. "Chitty No. 42 - 1 Lakh / 20 Months"
CREATE TABLE IF NOT EXISTS chit_schemes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  chit_value DECIMAL(12,2) NOT NULL,          -- total pot value, e.g. 100000.00
  duration_months INT NOT NULL,               -- number of months = number of tickets
  monthly_subscription DECIMAL(12,2) NOT NULL, -- chit_value / duration_months
  commission_percent DECIMAL(5,2) NOT NULL DEFAULT 5.00,
  start_date DATE NOT NULL,                   -- month 1 auction date
  status ENUM('active','closed') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One ticket/subscription held by a member in a scheme. A member could hold more than one ticket.
CREATE TABLE IF NOT EXISTS subscriptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  scheme_id INT NOT NULL,
  user_id INT NOT NULL,
  ticket_number INT NOT NULL,
  has_won TINYINT(1) NOT NULL DEFAULT 0,
  won_month INT NULL,
  UNIQUE KEY uniq_scheme_ticket (scheme_id, ticket_number),
  CONSTRAINT fk_sub_scheme FOREIGN KEY (scheme_id) REFERENCES chit_schemes(id),
  CONSTRAINT fk_sub_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One auction per scheme per month
CREATE TABLE IF NOT EXISTS auctions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  scheme_id INT NOT NULL,
  month_number INT NOT NULL,
  auction_date DATE NOT NULL,
  winning_subscription_id INT NULL,
  bid_amount DECIMAL(12,2) NULL,
  prize_amount DECIMAL(12,2) NULL,
  commission_amount DECIMAL(12,2) NULL,
  dividend_pool DECIMAL(12,2) NULL,
  dividend_per_ticket DECIMAL(12,2) NULL,
  net_installment DECIMAL(12,2) NULL,
  notes VARCHAR(255) NULL,
  UNIQUE KEY uniq_scheme_month (scheme_id, month_number),
  CONSTRAINT fk_auction_scheme FOREIGN KEY (scheme_id) REFERENCES chit_schemes(id),
  CONSTRAINT fk_auction_winner FOREIGN KEY (winning_subscription_id) REFERENCES subscriptions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per ticket per month: what is due, what has been paid
CREATE TABLE IF NOT EXISTS installments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  scheme_id INT NOT NULL,
  subscription_id INT NOT NULL,
  month_number INT NOT NULL,
  amount_due DECIMAL(12,2) NOT NULL,
  amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
  paid_date DATE NULL,
  status ENUM('pending','partial','paid') NOT NULL DEFAULT 'pending',
  UNIQUE KEY uniq_sub_month (subscription_id, month_number),
  CONSTRAINT fk_inst_scheme FOREIGN KEY (scheme_id) REFERENCES chit_schemes(id),
  CONSTRAINT fk_inst_sub FOREIGN KEY (subscription_id) REFERENCES subscriptions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_sub_scheme ON subscriptions(scheme_id);
CREATE INDEX idx_inst_scheme_month ON installments(scheme_id, month_number);
