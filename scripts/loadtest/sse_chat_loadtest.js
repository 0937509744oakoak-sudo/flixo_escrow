// sse_chat_loadtest.js — k6 load test for FLIXO real-time chat (SSE + Redis Pub/Sub)
//
// จำลอง:
//   1) Virtual Users จำนวนมากเปิด SSE connection (controllers/ChatStream.php) ค้างไว้
//   2) Senders ยิง HTTP POST (ChatController.php action=send) เข้ามาเรื่อย ๆ
//      → พิสูจน์ว่า Redis Pub/Sub กระจายข้อความให้ subscribers ได้ทันที
//        โดย DB ทำงานเบา (query id>lastId เฉพาะตอนมี event เท่านั้น ไม่ poll ถี่)
//
// วิธีทำงานของสคริปต์ (ทำไมใช้ http.get แทน EventSource):
//   ChatStream.php มี "bounded duration" ~30s แล้วปิด connection ให้ client reconnect
//   เอง. k6 http.get() จะถือ connection ไว้จนเซิร์ฟเวอร์ปิด (~30s) แล้ว return body
//   ที่สะสม event มา — เรานับจำนวน "event: message" จาก body ได้ตรง ๆ โดยไม่ต้องพึ่ง
//   โมดูล SSE ทดลอง (ใช้ได้กับ k6 ทุกเวอร์ชัน). แต่ละ iteration = 1 SSE cycle แล้ว
//   reconnect ทันที เลียนแบบพฤติกรรม EventSource จริง.
//
// Prerequisite: seed users ก่อน
//   php scripts/loadtest/seed_loadtest_users.php --count=100
//
// Run:
//   k6 run -e BASE_URL=http://localhost/flixo_escrow scripts/loadtest/sse_chat_loadtest.js
//
// Tunables (ผ่าน -e KEY=VALUE):
//   BASE_URL       (default http://localhost/flixo_escrow)
//   ROOMS          จำนวนห้องสนทนา = จำนวน user-pair ที่ login (default 50 → ใช้ 100 users)
//   SUBSCRIBERS    จำนวน VU ที่เปิด SSE ค้าง (default 1000)
//   SENDERS        จำนวน VU ที่ยิงข้อความ (default 50)
//   DURATION       ระยะเวลาเทส (default 2m)
//   RAMP           ระยะ ramp-up ของ subscribers (default 30s)
//   SEND_SLEEP     หน่วง (วินาที) ระหว่างการ send ของแต่ละ sender (default 2)
//   PASSWORD       รหัสผ่าน seed (default LoadTest!2026, ต้องตรงกับตอน seed)

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Trend, Rate } from 'k6/metrics';

const BASE_URL    = (__ENV.BASE_URL || 'http://localhost/flixo_escrow').replace(/\/$/, '');
const ROOMS       = parseInt(__ENV.ROOMS || '50', 10);
const SUBSCRIBERS = parseInt(__ENV.SUBSCRIBERS || '1000', 10);
const SENDERS     = parseInt(__ENV.SENDERS || '50', 10);
const DURATION    = __ENV.DURATION || '2m';
const RAMP        = __ENV.RAMP || '30s';
const SEND_SLEEP  = parseFloat(__ENV.SEND_SLEEP || '2');
const PASSWORD    = __ENV.PASSWORD || 'LoadTest!2026';

const LOGIN_URL  = `${BASE_URL}/login.php`;
const STREAM_URL = `${BASE_URL}/controllers/ChatStream.php`;
const SEND_URL   = `${BASE_URL}/controllers/ChatController.php`;

// ---- custom metrics ----
const sseConnects   = new Counter('sse_connects');           // จำนวน SSE cycle ที่เปิดสำเร็จ
const sseConnectOk  = new Rate('sse_connect_success');       // อัตราเปิดสำเร็จ (status 200)
const sseEvents     = new Counter('sse_events_received');    // จำนวน event:message ที่ได้รับรวม
const sseHoldTime   = new Trend('sse_hold_time_ms', true);   // ถือ connection ได้นานเท่าไร (~30000)
const sendDuration  = new Trend('chat_send_duration_ms', true);
const sendOk        = new Rate('chat_send_success');

export const options = {
    scenarios: {
        // subscribers: เปิด SSE ค้าง แล้ว reconnect วน จนจบ DURATION
        subscribers: {
            executor: 'ramping-vus',
            exec: 'subscriber',
            startVUs: 0,
            stages: [
                { duration: RAMP, target: SUBSCRIBERS },
                { duration: DURATION, target: SUBSCRIBERS },
                { duration: '10s', target: 0 },
            ],
            gracefulStop: '40s',
        },
        // senders: ยิงข้อความเข้าห้องต่าง ๆ เพื่อ trigger Redis publish
        senders: {
            executor: 'constant-vus',
            exec: 'sender',
            vus: SENDERS,
            duration: DURATION,
            startTime: '10s', // ให้ subscribers เริ่มเชื่อมต่อก่อน
            gracefulStop: '10s',
        },
    },
    thresholds: {
        'sse_connect_success': ['rate>0.95'],
        'chat_send_success': ['rate>0.95'],
        'chat_send_duration_ms': ['p(95)<1000'], // Redis publish ต้องไม่หน่วง send
    },
    // SSE ถือ connection นาน — อย่าให้ k6 ตัดสินว่า iteration ช้าผิดปกติ
    setupTimeout: '120s',
    // ปลายทางสำหรับ k6 cloud / load report (แก้ 404/E3 Resource does not exist)
    ext: {
        loadreport: {
            project: 'Default project',
        },
    },
};

// -------------------------------------------------------------------------
// setup(): login user pool (2 คนต่อห้อง) แล้วเก็บ session id + csrf token
// ส่งต่อให้ VU ใช้ (คืนเป็น JSON-serializable object)
// -------------------------------------------------------------------------
function cookieVal(res, name) {
    if (res.cookies && res.cookies[name] && res.cookies[name].length) {
        return res.cookies[name][0].value;
    }
    return null;
}

function login(username) {
    // 1) GET เพื่อรับ session cookie เริ่มต้น + scrape csrf token จากฟอร์ม
    const r1 = http.get(LOGIN_URL, { redirects: 0, tags: { name: 'login_get' } });
    const m = r1.body && r1.body.match(/name="csrf_token"\s+value="([^"]+)"/);
    const csrf = m ? m[1] : null;
    const sid0 = cookieVal(r1, 'flixo_sess');
    if (!csrf || !sid0) {
        return null;
    }

    // 2) POST login (session_regenerate_id → cookie ใหม่, แต่ csrf ใน session คงเดิม)
    const r2 = http.post(
        LOGIN_URL,
        { username: username, password: PASSWORD, csrf_token: csrf },
        { redirects: 0, headers: { Cookie: `flixo_sess=${sid0}` }, tags: { name: 'login_post' } }
    );
    const sid = cookieVal(r2, 'flixo_sess') || sid0;

    // login สำเร็จ → 302 redirect ไป dashboard
    if (r2.status !== 302) {
        return null;
    }
    return { name: username, sid: sid, csrf: csrf };
}

export function setup() {
    const rooms = [];
    let failures = 0;

    for (let r = 0; r < ROOMS; r++) {
        const aName = sprintf(2 * r);
        const bName = sprintf(2 * r + 1);
        const a = login(aName);
        const b = login(bName);
        if (a && b) {
            rooms.push({ a: a, b: b });
        } else {
            failures++;
        }
    }

    if (rooms.length === 0) {
        throw new Error(
            'No rooms could be authenticated. ตรวจสอบว่า seed users แล้ว ' +
            '(php scripts/loadtest/seed_loadtest_users.php) และ BASE_URL/PASSWORD ถูกต้อง'
        );
    }
    console.log(`setup: authenticated ${rooms.length}/${ROOMS} rooms (${failures} failed)`);
    return { rooms: rooms };
}

function sprintf(i) {
    return 'loadtest_user_' + String(i).padStart(4, '0');
}

// -------------------------------------------------------------------------
// subscriber: เปิด SSE ค้าง ~30s แล้ว reconnect (loop โดย executor)
//   auth เป็น user B ของห้อง, subscribe partner = user A
// -------------------------------------------------------------------------
export function subscriber(data) {
    const rooms = data.rooms;
    const room = rooms[(__VU - 1) % rooms.length];
    const me = room.b;       // ผู้ฟัง
    const partner = room.a;  // คู่สนทนา

    const url = `${STREAM_URL}?partner=${encodeURIComponent(partner.name)}`;
    const params = {
        headers: { Cookie: `flixo_sess=${me.sid}` },
        timeout: '45s', // เผื่อ MAX_LIFETIME 30s + keep-alive
        tags: { name: 'sse_stream' },
    };

    const res = http.get(url, params);

    const ok = res.status === 200;
    sseConnects.add(1);
    sseConnectOk.add(ok);
    sseHoldTime.add(res.timings.duration);

    if (ok && res.body) {
        const matches = res.body.match(/event: message/g);
        if (matches) {
            sseEvents.add(matches.length);
        }
    }
    // ไม่ sleep — ให้ reconnect ทันทีเหมือน EventSource
}

// -------------------------------------------------------------------------
// sender: ยิง action=send เข้าห้อง (auth เป็น user A → partner user B)
//   ทุก publish จะกระจายไป subscribers ทุกคนในห้องนั้นผ่าน Redis
// -------------------------------------------------------------------------
export function sender(data) {
    const rooms = data.rooms;
    const room = rooms[(__VU - 1) % rooms.length];
    const me = room.a;
    const partner = room.b;

    const payload = {
        action: 'send',
        partner: partner.name,
        message: `loadtest ${Date.now()} vu${__VU}`,
        csrf_token: me.csrf,
    };
    const params = {
        headers: {
            Cookie: `flixo_sess=${me.sid}`,
            'X-CSRF-Token': me.csrf,
        },
        tags: { name: 'chat_send' },
    };

    const res = http.post(SEND_URL, payload, params);
    sendDuration.add(res.timings.duration);
    const ok = check(res, {
        'send status 200': (r) => r.status === 200,
        'send not CSRF/403': (r) => r.status !== 403,
    });
    sendOk.add(ok);

    sleep(SEND_SLEEP);
}
