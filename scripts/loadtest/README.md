# Load Testing — FLIXO Real-time Chat (SSE + Redis Pub/Sub)

พิสูจน์ว่าสถาปัตยกรรมใหม่ (SSE + Redis + S3) แก้ "คอขวดวิกฤต" ของ HTTP Polling ได้จริง
และรับ concurrent users ได้มากกว่าเดิม

---

## 1. เครื่องมือที่แนะนำ: **k6** (ไม่ใช่ Artillery)

| หัวข้อ | k6 | Artillery |
|---|---|---|
| Engine | Go (goroutine) — ถือ connection ค้างได้หลักพัน–หมื่นต่อเครื่อง | Node.js (event loop) — จำกัดกว่าเมื่อถือ SSE ค้างจำนวนมาก |
| การถือ SSE ค้าง | เหมาะมาก (แต่ละ VU ใช้ goroutine เบา ๆ) | ทำได้แต่กิน RAM/CPU เร็วกว่าที่ระดับ 1,000 conn |
| รันใน Local | ไบนารีเดียว ติดตั้งง่าย | ต้องมี Node ecosystem |
| Scripting | JS (ES6) | YAML + JS |
| Metrics | ละเอียด (p95/p99, custom metrics) built-in | มี แต่ต้อง plugin เพิ่มบ่อย |

**สรุป:** สำหรับเป้าหมาย "ถือ SSE ค้าง 500–1,000 connection พร้อมกัน" ในเครื่อง Local
→ **k6 เหมาะที่สุด** เพราะ goroutine ถือ long-lived connection ได้เยอะโดยใช้ทรัพยากรน้อย

### ติดตั้ง k6 (Windows)
```powershell
winget install k6 --source winget
# หรือ
choco install k6
```
ตรวจสอบ: `k6 version`

---

## 2. ขั้นตอนการรันเทส

### 2.1 เตรียม server (ครั้งเดียว)
1. เปิด Apache + MySQL (XAMPP)
2. เปิด Redis (สำคัญ! ต้องมี Redis + phpredis เพื่อทดสอบ Pub/Sub path จริง)
   - ถ้าไม่มี Redis ระบบจะ fallback เป็น DB polling อัตโนมัติ — ยังเทสได้แต่จะวัด
     "path เดิม" ไม่ใช่ Redis path
   - ตรวจ phpredis: `php -m | findstr redis`
3. ตั้ง ENV ให้ PHP เห็น Redis (ถ้าจำเป็น): `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `REDIS_DB`

### 2.2 Seed ผู้ใช้สำหรับเทส (DEV เท่านั้น)
```powershell
# สร้าง 100 users = 50 ห้อง (a/b pair)  → รหัสผ่าน LoadTest!2026
php scripts/loadtest/seed_loadtest_users.php --count=100
```
> สคริปต์นี้ **ปฏิเสธการรันเมื่อ `APP_ENV=production`** เพื่อความปลอดภัย

### 2.3 รันเทส
```powershell
# ค่า default: 1000 subscribers + 50 senders, 2 นาที
k6 run -e BASE_URL=http://localhost/flixo_escrow scripts/loadtest/sse_chat_loadtest.js

# ปรับแต่งได้
k6 run -e BASE_URL=http://localhost/flixo_escrow `
       -e SUBSCRIBERS=1000 -e SENDERS=50 -e ROOMS=50 `
       -e DURATION=3m -e RAMP=45s `
       scripts/loadtest/sse_chat_loadtest.js
```

### 2.4 ล้างข้อมูลหลังเทส
```powershell
php scripts/loadtest/seed_loadtest_users.php --cleanup
```

---

## 3. สคริปต์ทำงานอย่างไร (สรุปสำคัญ)

- **subscribers**: แต่ละ VU login เป็น user `b` ของห้อง แล้วเปิด `ChatStream.php?partner=<a>`
  ค้างไว้ ~30s (bounded duration) แล้ว **reconnect** วนไปจนจบ — เลียนแบบ `EventSource` จริง
- **senders**: login เป็น user `a` ยิง `action=send` ไปหา `b` เป็นระยะ ๆ
  → INSERT DB สำเร็จ → `chatRealtimePublish()` publish เข้า Redis channel ของคู่นั้น
  → subscribers ทุกคนในห้อง (subscribe channel เดียวกัน) ได้ event ทันที
- เพราะ CSRF token เป็น **persistent ต่อ session** (ไม่ rotate) จึง scrape ครั้งเดียวใน
  `setup()` แล้วใช้ซ้ำได้ทุก request

> **หมายเหตุเรื่อง fan-out:** แชทเป็น 1:1 ตาม design จริง หลาย subscriber ในห้องเดียวกัน
> ในเทสนี้เป็นการ "จำลอง fan-out" เพื่อกดดัน Redis + PHP worker ให้เห็นภาพชัด
> ปรับ `ROOMS` ให้มากขึ้น (subscriber ต่อห้องน้อยลง) เพื่อจำลองแบบสมจริง 1:1 มากขึ้น

---

## 4. Metrics ที่ควรจับตา

### 4.1 จากผล k6 (บนหน้าจอหลังรันจบ)
| Metric | ความหมาย / เกณฑ์ |
|---|---|
| `sse_connect_success` (rate) | สัดส่วน SSE ที่เปิดได้ status 200 — ควร > 95% |
| `sse_hold_time_ms` (p95) | ควรใกล้ ~30,000ms (ถือ connection ครบ lifetime = worker ไม่ตายกลางคัน) |
| `sse_events_received` | จำนวน event ที่ subscribers ได้รับรวม — ควรเพิ่มตามจำนวน send × fan-out |
| `chat_send_duration_ms` (p95) | ควร < 1000ms — ถ้าพุ่งแสดงว่า Redis publish หรือ DB write เป็นคอขวด |
| `chat_send_success` (rate) | ควร > 95% (ถ้าตก 403 = CSRF/session หลุด) |
| `http_req_failed` | อัตรา request ล้มเหลวรวม |

### 4.2 ฝั่ง Server (จับคู่กันดูระหว่างรัน)
| ทรัพยากร | ดูอะไร / เครื่องมือ |
|---|---|
| **PHP workers (Apache/PHP-FPM)** | จำนวน busy workers — SSE จะถือ worker ไว้ 1 ตัว/connection ตลอด ~30s. นี่คือข้อจำกัดหลัก: `MaxRequestWorkers` (Apache) / `pm.max_children` (FPM) ต้อง ≥ concurrent SSE |
| **CPU / RAM ของ PHP** | Task Manager / `htop`. SSE ที่ idle ควรกิน CPU ต่ำ (บล็อกที่ Redis read timeout) |
| **DB (MySQL)** | `SHOW GLOBAL STATUS LIKE 'Questions'` / `Com_select` ก่อน-หลัง. **จุดพิสูจน์สำคัญ:** query count ควรโตตาม "จำนวนข้อความที่ส่ง" ไม่ใช่ตาม "จำนวน connection × เวลา" (ต่างจาก polling เดิมที่ query ทุก 3 วิ ต่อทุก user) |
| **Redis** | `redis-cli INFO stats` → `total_commands_processed`, `pubsub_channels`, `connected_clients` |
| **Active connections** | `netstat -an | findstr :80 | find /c "ESTABLISHED"` |

### 4.3 การเปรียบเทียบ "เก่า vs ใหม่" (แนะนำ)
1. **รอบ A (ใหม่):** เปิด Redis → รันเทส → บันทึก `Com_select` ของ MySQL + CPU
2. **รอบ B (จำลองเก่า):** ปิด Redis (ให้ fallback เป็น DB poll ทุก 1.5s) → รันเทสซ้ำ
3. เทียบ `Com_select`/วินาที: รอบ A ควรต่ำกว่ารอบ B อย่างชัดเจนเมื่อ traffic เท่ากัน
   → นี่คือหลักฐานว่า Redis Pub/Sub ตัดภาระ DB ได้จริง

---

## 5. ข้อควรระวัง / ปรับจูน

- **Apache `MaxRequestWorkers` เป็นคอขวดแรก**: ถ้า SSE 1,000 connection แต่ worker มี 150
  → connection ส่วนเกินจะรอคิว/ถูกปฏิเสธ. เพิ่มค่าใน `httpd-mpm.conf` (event MPM แนะนำ)
  ก่อนสรุปว่า "ระบบรับไม่ไหว"
- **ปิด Gzip เฉพาะ ChatStream** ตามที่ตั้งใน `controllers/.htaccess` แล้ว — ยืนยันว่า event
  ถูก flush ทันที (ไม่ถูก buffer)
- เครื่อง Local ที่รัน k6 + Apache + MySQL + Redis พร้อมกัน ทรัพยากรจะแชร์กัน → ตัวเลข
  สัมบูรณ์อาจไม่เท่า production แต่ **แนวโน้มการเปรียบเทียบ** ยังเชื่อถือได้
- ถ้าจะยิงเกิน ~1,000 VU บน Windows อาจชน limit ของ ephemeral ports — รันหลายเครื่อง
  หรือใช้ `k6 run --address` แบบ distributed
