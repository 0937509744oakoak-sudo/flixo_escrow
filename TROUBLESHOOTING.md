# FLIXO Troubleshooting Guide

## Problem: ERR_TOO_MANY_REDIRECTS After Session Hardening

### Symptom
```
localhost redirected you too many times.
Try deleting the cookies for this site
ERR_TOO_MANY_REDIRECTS
```

### Root Cause
When the session security system was updated:
- Old session name changed from `PHPSESSID` to `flixo_session`  
- Browser still has cookies with old name
- New code doesn't recognize old session
- User appears not logged in
- Causes redirect loop: login → dashboard → login → ...

### Quick Fix (for users encountering this)

#### Option 1: Clear Browser Cookies (Recommended)
1. Open browser DevTools (F12 or Right-click → Inspect)
2. Go to **Application** tab → **Cookies**
3. Find `localhost` (or your domain)
4. Delete all cookies (especially `PHPSESSID` and `flixo_session`)
5. Close all tabs and reopen
6. Try login again

#### Option 2: Use Incognito/Private Window
1. Open a new Incognito/Private window
2. Go to the FLIXO website
3. Try login again
4. If this works, you need to clear cookies (see Option 1)

#### Option 3: Hard Refresh Browser
1. Press `Ctrl+Shift+R` (Windows/Linux) or `Cmd+Shift+R` (Mac)
2. This clears cached resources
3. Try login again

### Verification That It's Fixed

After applying the fix:
1. Go to login page
2. Enter valid credentials
3. Should redirect to dashboard successfully
4. No infinite redirect loop

### For Developers: What Changed

**Before:**
```php
// Each page had this
session_start();
```

**After:**
```php
// Centralized in includes/session.php
// All pages use: require 'includes/session.php';
// Handles:
// - Session name: 'flixo_session'
// - Security settings (HttpOnly, SameSite, etc.)
// - Old cookie cleanup (PHPSESSID removal)
// - CSRF token generation
```

### Why This Is Better

1. **Security**: Consistent session configuration across app
2. **Maintenance**: One place to update session settings
3. **Cookie Cleanup**: Automatically removes old cookies
4. **CSRF Protection**: Token auto-generated and validated

---

## Other Common Issues

### Issue: "Unauthorized Admin Only" Error

**Cause**: Your user account doesn't have admin role

**Fix**: 
1. Contact database administrator
2. Check your `users` table, your `role` column should be `'admin'`
3. SQL to verify:
   ```sql
   SELECT username, role FROM users WHERE username = 'your_username';
   ```
4. If role is not 'admin', update it:
   ```sql
   UPDATE users SET role = 'admin' WHERE username = 'your_username' LIMIT 1;
   ```

### Issue: "Database Connection Failed"

**Cause**: Wrong database credentials in environment

**Fix**:
1. Check your `.env` file (or set environment variables)
2. Verify values:
   ```
   DB_HOST=127.0.0.1
   DB_PORT=3307
   DB_USER=root
   DB_PASS=your_password
   DB_NAME=flixo_db
   ```
3. Test MySQL connection:
   ```bash
   mysql -h 127.0.0.1 -u root -p -P 3307 flixo_db
   ```
4. Make sure MySQL server is running

### Issue: "CSRF Error: Security Check Failed"

**Cause**: Either:
- Session expired
- Token not being sent
- Token mismatch (timing issue)

**Fix**:
1. Clear browser cache and cookies
2. Disable browser cache for development (DevTools → Network → Disable cache)
3. Make sure JavaScript is enabled (CSRF token is auto-injected)
4. Check console for errors (F12 → Console tab)

### Issue: "File Upload Failed"

**Cause**: Usually file permissions or size limit

**Fix**:
1. Check directory permissions:
   ```bash
   ls -la uploads/
   ls -la uploads/kyc/
   ls -la uploads/slips/
   # Should show: drwxr-xr-x (755) or drwxrwxr-x (775)
   ```
2. Make directories writable:
   ```bash
   chmod 755 uploads/
   chmod 755 uploads/kyc/
   chmod 755 uploads/slips/
   ```
3. Check file size limit:
   - Max size: 5MB (configurable in `order_helper.php`)
   - Allowed formats: JPG, PNG, WEBP

---

## Performance Checks

### Is the app slow?

**Check what's running:**
```bash
# MySQL processes
ps aux | grep mysql

# PHP processes
ps aux | grep php

# Open ports
netstat -an | grep LISTEN
```

**Common causes:**
1. Database server not running → Start MySQL
2. Too many processes → Restart services
3. Memory issues → Check system memory usage

### Database optimization

```sql
-- Check active connections
SHOW PROCESSLIST;

-- Check slow queries
SHOW VARIABLES LIKE 'slow_query_log';
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 2;

-- Check index usage
EXPLAIN SELECT * FROM orders WHERE id = 1;
-- Should show: type='const' or 'ref'
```

---

## Security Audit Checklist

Before going to production, verify:

- [ ] All pages require session.php or auth.php
- [ ] No hardcoded secrets in PHP files
- [ ] .env file is not in version control (.gitignore includes .env)
- [ ] HTTPS is enabled (certificate should be valid)
- [ ] File permissions are correct (chmod 600 for .env, 755 for dirs)
- [ ] Error messages don't expose system details
- [ ] All SQL queries use prepared statements
- [ ] All HTML output is escaped with htmlspecialchars()
- [ ] API calls verify SSL certificates
- [ ] Admin functions check $_SESSION['role'] === 'admin'

---

## Getting Help

### If you're stuck:

1. **Check error logs:**
   ```bash
   # PHP error log (location varies)
   tail -f /var/log/php-errors.log
   # Or check php.ini for error_log location
   
   # Application logs
   cat debug.log  # if created
   ```

2. **Enable debug mode (development only!):**
   ```php
   // At the top of any page:
   error_reporting(E_ALL);
   ini_set('display_errors', 1);
   ```

3. **Check browser console:**
   - F12 → Console tab
   - Look for JavaScript errors
   - Check network requests (Network tab) for failed API calls

4. **Review recent changes:**
   - Look at Git history if using version control
   - Last change was session system hardening
   - This shouldn't affect most functionality

### Contact Support

For issues not covered here:
- Check SECURITY_IMPROVEMENTS.md for technical details
- Review the source code with comments
- Look for TODO comments in files

---

## Session Timeout

Default: Sessions expire after **24 hours of inactivity**

To change:
```php
// In includes/session.php, modify:
session_set_cookie_params([
    'lifetime' => 86400,  // 24 hours in seconds
    // ...
]);
// Change 86400 to desired timeout in seconds:
// 3600 = 1 hour
// 1800 = 30 minutes
// 604800 = 1 week
```

---

Last Updated: 2026-05-28
Version: 1.0 Post-Security-Hardening
