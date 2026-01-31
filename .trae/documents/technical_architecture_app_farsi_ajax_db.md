## 1.Architecture design
```mermaid
graph TD
  A["مرورگر کاربر"] --> B["UI (Bootstrap RTL + jQuery)"]
  B --> C["AJAX Requests"]
  C --> D["PHP 8.2 (core.php)"]
  D --> E["MySQL"]

  subgraph "Frontend"
    B
    C
  end

  subgraph "Backend"
    D
  end

  subgraph "Database"
    E
  end
```

## 2.Technology Description
- Frontend: Bootstrap 5 (RTL) + jQuery (AJAX) + Vazirmatn (Local)
- Backend: PHP 8.2 (یک فایل منطق: core.php) + Session-based Auth
- Database: MySQL (utf8mb4)
- Package manager: Composer (vendor/autoload.php)
- Jalali Date: morilog/jalali
- Iranian Validation: persian-validator/national-code + persian-validator/mobile

## 3.Route definitions
| Route | Purpose |
|-------|---------|
| /index.php | UI اصلی (نمایش صفحه ورود یا داشبورد بر اساس session) |
| /core.php | Endpoint واحد برای همه عملیات AJAX (JSON) |

## 6.Data model(if applicable)

### 6.1 Data model definition
```mermaid
erDiagram
  USERS ||--o{ ITEMS : owns
  USERS ||--o{ AUDIT_LOGS : acts

  USERS {
    int id
    string email
    string password_hash
    string role
    string display_name
    string mobile
    string national_code
    boolean is_active
    datetime created_at
    datetime last_login_at
  }

  ITEMS {
    int id
    int owner_id
    string title
    string content
    datetime created_at
    datetime updated_at
  }

  AUDIT_LOGS {
    int id
    int actor_id
    string action
    string entity
    int entity_id
    int target_user_id
    datetime created_at
    string ip
    string user_agent
  }
```

### 6.2 Data Definition Language
Users (users)
```
CREATE TABLE items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  content TEXT,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
);

CREATE INDEX idx_items_owner_id ON items(owner_id);
CREATE INDEX idx_items_created_at ON items(created_at DESC);

CREATE TABLE audit_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id INT UNSIGNED NOT NULL,
  action VARCHAR(30) NOT NULL,
  entity VARCHAR(30) NOT NULL,
  entity_id INT UNSIGNED,
  target_user_id INT UNSIGNED,
  ip VARCHAR(45),
  user_agent VARCHAR(255),
  created_at DATETIME NOT NULL
);

CREATE INDEX idx_audit_logs_actor_id ON audit_logs(actor_id);
CREATE INDEX idx_audit_logs_created_at ON audit_logs(created_at DESC);

-- دسترسی‌ها در این معماری از طریق MySQL User/Permission + منطق PHP اعمال می‌شود.
-- (RLS نداریم؛ کنترل دسترسی در core.php انجام می‌شود.)
```
