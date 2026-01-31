## 1.Architecture design
```mermaid
graph TD
  A["مرورگر کاربر"] --> B["اپلیکیشن PHP (Server-Rendered صفحات)"]
  B --> C["پایگاه‌داده MySQL"]
  B --> D["کتابخانه تاریخ جلالی (morilog/jalali)"]

  subgraph "Frontend Layer"
    A
  end

  subgraph "Backend Layer"
    B
    D
  end

  subgraph "Data Layer"
    C
  end
```

## 2.Technology Description
- Frontend: HTML + CSS + JavaScript (حداقلی برای به‌روزرسانی ساعت)
- Backend: PHP@8.2
- Database: MySQL
- Date/Locale: morilog/jalali (نمایش تاریخ شمسی)

## 3.Route definitions
| Route | Purpose |
|---|---|
| /dashboard | صفحه پنل کاربری + منو + هدر مشترک |
| /profile | صفحه پروفایل به‌صورت مستقل از منو |
| /class-number/create | صفحه ایجاد شماره کلاسه (فرم) |
| /class-number (POST) | ثبت ایجاد شماره کلاسه و برگشت نتیجه |

## 6.Data model(if applicable)
### 6.1 Data model definition
```mermaid
erDiagram
  OFFICE ||--o{ USER : "has"
  USER ||--o{ CLASS_NUMBER : "creates"

  OFFICE {
    int id
    string city
    string display_name
  }
  USER {
    int id
    int office_id
    string name
  }
  CLASS_NUMBER {
    int id
    int user_id
    string class_number
    datetime created_at
  }
```

### 6.2 Data Definition Language
OFFICE
```
CREATE TABLE office (
  id INT AUTO_INCREMENT PRIMARY KEY,
  city VARCHAR(80) NOT NULL,
  display_name VARCHAR(120) NOT NULL
);
```
USER
```
CREATE TABLE user (
  id INT AUTO_INCREMENT PRIMARY KEY,
  office_id INT NULL,
  name VARCHAR(120) NOT NULL
);
```
CLASS_NUMBER
```
CREATE TABLE class_number (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  class_number VARCHAR(60) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```