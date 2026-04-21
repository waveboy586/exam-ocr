# Exam-OCR

ระบบ OCR ข้อสอบ + จัดการการสอบออนไลน์ (PHP + Python + MySQL)

---

## 🐳 Run with Docker (แนะนำ)

### 1. ติดตั้ง Docker Desktop
https://www.docker.com/products/docker-desktop

### 2. เตรียม `.env`
```bash
cp .env.example .env
```
จากนั้นแก้ `TYPHOON_API_KEY` ในไฟล์ `.env` ให้เป็น API key ของคุณ

### 3. Build + Run
```bash
docker compose up -d --build
```

ครั้งแรกจะใช้เวลาสักพัก (ติดตั้ง PHP extensions + Python packages)

### 4. เข้าใช้งาน
| Service    | URL                                 |
| ---------- | ----------------------------------- |
| เว็บแอป    | http://localhost:8080               |
| phpMyAdmin | http://localhost:8081               |
| MySQL      | `localhost:3307` (user: `root`)     |

### 5. Import schema ฐานข้อมูลเดิม
ถ้ามีไฟล์ dump จาก XAMPP อยู่แล้ว:
```bash
docker exec -i exam_ocr_db mysql -uroot -prootpass exam_ocr < your_dump.sql
```
หรือ import ผ่าน phpMyAdmin ที่ `http://localhost:8081`

### คำสั่งที่ใช้บ่อย
```bash
docker compose logs -f web           # ดู log
docker compose exec web bash         # เข้าไปใน container
docker compose down                  # หยุด service
docker compose down -v               # หยุด + ลบ volume (ข้อมูล DB หายหมด!)
```

---

## 💻 Run with XAMPP (เดิม, ไม่ใช้ Docker)

### ติดตั้ง Python dependencies
```powershell
cd C:\xampp\htdocs\exam-ocr
python -m venv venv
.\venv\Scripts\Activate
pip install -r requirements.txt
pip install qwen-vl-utils
```

### เตรียมฐานข้อมูล
- สร้าง database `exam_ocr` ใน phpMyAdmin
- Import schema
- `config.php` จะใช้ `localhost` / `root` / ว่าง เป็น default (ตามเดิม)

### เตรียม `.env`
```
TYPHOON_API_KEY=your_API_key_here
```

เข้า `http://localhost/exam-ocr/web/`

---

## 📁 โครงสร้าง

```
exam-ocr/
├── Dockerfile              # PHP 8.2 + Apache + Python 3
├── docker-compose.yml      # web + db + phpmyadmin
├── docker/
│   ├── apache-vhost.conf   # DocumentRoot → /web
│   ├── php.ini             # upload/memory limits
│   ├── entrypoint.sh       # permissions + env passthrough
│   └── init.sql            # create database
├── ocr.py                  # PDF → Markdown (Typhoon OCR)
├── parse_exam.py           # Markdown → JSON
├── import_to_mysql.py      # JSON → MySQL
├── requirements.txt
└── web/
    ├── admin/              # หน้า admin
    ├── teacher/            # หน้าอาจารย์ + auto-grade worker
    └── student/            # หน้าผู้เรียน
```

---

## ⚙️ Environment Variables

ทุก config ของโปรเจค (ทั้ง PHP และ Python) อ่านจาก env โดยมี fallback เป็นค่า XAMPP เดิม ทำให้รันได้ทั้งสอง mode:

| Variable          | Default (XAMPP) | Default (Docker) |
| ----------------- | --------------- | ---------------- |
| `DB_HOST`         | `localhost`     | `db`             |
| `DB_NAME`         | `exam_ocr`      | `exam_ocr`       |
| `DB_USER`         | `root`          | `root`           |
| `DB_PASSWORD`     | (ว่าง)          | `rootpass`       |
| `PYTHON_BIN`      | venv/Scripts    | `/usr/bin/python3` |
| `TYPHOON_API_KEY` | จาก `.env`      | จาก `.env`       |
