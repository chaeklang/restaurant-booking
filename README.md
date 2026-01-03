# restaurant-booking
# เว็บไซต์จองโต๊ะร้านอาหารออนไลน์ (PHP + MySQL)

โปรเจกต์เว็บแอปจองโต๊ะร้านอาหารแบบไฟล์เดียว (Single-file) พัฒนาด้วย **PHP + MySQL** พร้อมหน้าเว็บ Responsive ด้วย **Bootstrap** และตรวจสอบข้อมูลแบบเรียลไทม์ด้วย **jQuery** รวมถึงรองรับการส่งอีเมลยืนยันผ่าน **PHPMailer (Gmail SMTP)**

**ผู้พัฒนา:** เบญญาภา แช่กลาง

---

## ฟีเจอร์หลัก
- จองโต๊ะออนไลน์: ชื่อ-นามสกุล, เบอร์โทร, อีเมล(ไม่บังคับ), วันที่, เวลา, จำนวนคน, หมายเหตุ
- Responsive UI รองรับมือถือ/แท็บเล็ต/คอมพิวเตอร์ (Bootstrap)
- ตรวจสอบความถูกต้องแบบเรียลไทม์ (jQuery Validation)
- ระบบเลือกเวลาจองอัตโนมัติเป็นช่วง (เช่น ทุก 15 นาที)
- กำหนดเวลาเปิด-ปิดร้าน และจำกัดเวลาจองของ “วันปัจจุบัน” (Cutoff)
- ป้องกันการจองซ้ำ (เบอร์โทร + วัน + เวลา)
- จำกัดจำนวนการจองต่อช่วงเวลา (max_per_slot)
- ตรวจสอบรายการจองย้อนหลังด้วยเบอร์โทร (แสดง 20 รายการล่าสุด)
- ส่งอีเมลยืนยัน/แจ้งเตือนร้าน (เมื่อเปิดใช้งาน SMTP)

---

## เทคโนโลยีที่ใช้
- PHP (PDO)
- MySQL
- HTML/CSS
- JavaScript + jQuery
- Bootstrap 5
- PHPMailer (ส่งเมลผ่าน Gmail SMTP)

---

## โครงสร้างไฟล์แนะนำ
> โค้ดหลักเป็นไฟล์เดียว แต่มีรูปประกอบและไฟล์ config เพิ่มเติม

ตัวอย่างโครงสร้าง:

restaurant-booking/
├─ codeweb.php
├─ vibe.png
├─ padthai.png
├─ grilledshrimp.png
├─ oysters.png
├─ .gitignore
├─ .env.example
└─ README.md

---

## การติดตั้งและรันบนเครื่อง (Local)

### 1) ติดตั้งเครื่องมือที่ต้องมี
- PHP 8+ (แนะนำ)
- MySQL / MariaDB (เช่น XAMPP)
- Composer (สำหรับ PHPMailer)

### 2) วางไฟล์โปรเจกต์
- ถ้าใช้ XAMPP: วางโฟลเดอร์ไว้ที่ `C:\xampp\htdocs\restaurant-booking`
- เข้าใช้งาน: `http://localhost/restaurant-booking/index.php`

### 3) ติดตั้ง PHPMailer
เปิด Terminal ในโฟลเดอร์โปรเจกต์ แล้วรัน:
```bash
composer require phpmailer/phpmailer

จะได้โฟลเดอร์ vendor/ และไฟล์ vendor/autoload.php


ตั้งค่าฐานข้อมูล (Database)
โค้ดรองรับการสร้างฐานข้อมูล/ตารางอัตโนมัติเมื่อ APP_ENV=local
ค่าเริ่มต้นในโค้ด:
DB Host: 127.0.0.1
DB User: root
DB Pass: (ว่าง)
DB Name: restaurant_booking_ui
Table: bookings
ถ้ารันแล้วฐานข้อมูลถูกสร้างแล้ว สามารถดูใน phpMyAdmin ได้ทันที


ตั้งค่าอีเมล (SMTP) — ไม่บังคับ
ระบบจะส่งเมลได้เมื่อมีการตั้งค่า SMTP_USER และ SMTP_PASS
สร้างไฟล์ .env (แนะนำ)
สร้างไฟล์ชื่อ .env แล้วใส่ค่า:

APP_ENV=local
AUTO_SETUP_DB=1

SMTP_USER=yourgmail@gmail.com
SMTP_PASS=your_app_password

หมายเหตุ: ถ้าใช้ Gmail แนะนำให้ใช้ App Password แทนรหัสผ่านจริง

ถ้ายังไม่ตั้งค่า SMTP
ระบบยังจองได้ปกติ แต่จะขึ้นข้อความว่า “บันทึกแล้ว (ยังไม่ตั้งค่า SMTP จึงไม่ส่งอีเมล)”

ข้อมูลสำคัญที่ปรับได้ (ในโค้ด)
ส่วน config['shop'] สามารถแก้:
open / close เวลาเปิด-ปิดร้าน
cutoff_today เวลาหลังจากนี้จะไม่รับจอง “ภายในวันเดียวกัน”
step_min ช่วงเวลาในการเลือกจอง (เช่น 15 นาที)
max_per_slot จำกัดจำนวนการจองต่อช่วงเวลา (0 = ไม่จำกัด)

ความปลอดภัยที่มีในระบบ
Session Hardening (secure/httponly/samesite)
CSRF Token
PDO Prepared Statements ป้องกัน SQL Injection
Validation ฝั่ง Server และ Client

วิธีใช้งาน
เข้าเมนู “จองโต๊ะ”
กรอกข้อมูล → กดยืนยัน
ระบบบันทึกลง MySQL และ (ถ้าเปิด SMTP) ส่งอีเมลยืนยัน
เข้าเมนู “ตรวจสอบการจอง” ใส่เบอร์โทร → ดูรายการจองล่าสุด

หมายเหตุ
รูปเมนูที่ใช้: vibe.png, padthai.png, grilledshrimp.png, oysters.png
ถ้าไม่มีรูป ระบบจะใช้ placeholder แทน

หน้าเว็บ
<img width="1903" height="1030" alt="home" src="https://github.com/user-attachments/assets/0f4dd787-78c7-4fc4-920f-43187b7a3fe2" />

การจองโต๊ะเเบบยังไม่ใส่เมล
<img width="1917" height="1032" alt="หน้าจองโต๊ะเเบบยังไม่ใส่gmail" src="https://github.com/user-attachments/assets/85881c3b-21a7-440d-a60d-d8672c1034c0" />

การจองโต๊ะเเบบใส่เมล
![หน้าจองโต๊ะเเบบใส่เมล](https://github.com/user-attachments/assets/34247184-0a44-4f36-95e8-b2606ad72688)

ดูรายชื่อการจองด้วยเบอร์โทร
<img width="1905" height="475" alt="ดูรายชื่อจองโต๊ะ" src="https://github.com/user-attachments/assets/fd1713fe-7f51-4953-a92e-0ef3484144ac" />

