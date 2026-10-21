#!/usr/bin/env python3
"""Integration checks against a running LOCAL Docker Compose project.
Only uniquely named test clients are removed; normal/demo clients are preserved.
Uses Python 3 standard library. Run from this project directory.
"""
import concurrent.futures
import http.cookiejar
import json
import re
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta
from pathlib import Path
from zoneinfo import ZoneInfo

BASE = 'http://127.0.0.1:8086'
MAIL = 'http://127.0.0.1:8026'
PREFIX = 'qa' + str(time.time_ns())
EMAILS = [PREFIX + suffix + '@example.test' for suffix in ('a', 'b')]
PASSWORD = 'TestTransport2026!'
RESULTS = []

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs): return None

class Session:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())
    def request(self, path, data=None):
        req = urllib.request.Request(BASE + path, data=urllib.parse.urlencode(data).encode() if data is not None else None)
        try: response = self.opener.open(req, timeout=30)
        except urllib.error.HTTPError as e: response = e
        return response.code, response.read().decode('utf-8'), response.headers
    def token(self, path='/?page=dashboard'):
        _, html, _ = self.request(path)
        return re.search(r'name="csrf" value="([a-f0-9]+)"', html)[1]
    def post(self, path, action, **fields):
        return self.request(path, {'csrf':self.token(path), 'action':action, **fields})

def check(name, condition, detail=''):
    RESULTS.append({'check':name, 'passed':bool(condition), 'detail':detail})
    print(('PASS ' if condition else 'FAIL ') + name)
    if not condition: raise AssertionError(name + ' ' + detail)

def sql(query):
    cmd=['docker','compose','exec','-T','db','sh','-c','exec mysql -N -u root -p"$MYSQL_ROOT_PASSWORD" transport_clients']
    return subprocess.run(cmd,input=query,text=True,capture_output=True,check=True).stdout.strip()

def latest_link(email, subject):
    with urllib.request.urlopen(MAIL+'/api/v1/messages?limit=100') as r: messages=json.load(r)['messages']
    for m in messages:
        if any(t['Address']==email for t in m['To']) and subject in m['Subject']:
            with urllib.request.urlopen(MAIL+'/api/v1/message/'+m['ID']) as r: full=json.load(r)
            return re.search(r'http://127\.0\.0\.1:8086/\?page=confirm&token=[a-f0-9]{64}',full['Text'])[0].removeprefix(BASE)
    raise AssertionError('Expected local email missing')

def registration(s,email):
    return s.post('/?page=register','register',full_name='Тестовий Клієнт',email=email,phone='+380671234567',password=PASSWORD,password_confirmation=PASSWORD,consent='1')

def order_data(**extra):
    data={'id':'','service_id':'2','origin':'Київ, вокзал','destination':'Львів, центр',
          'departure_at':(datetime.now(ZoneInfo('Europe/Kyiv'))+timedelta(days=5)).strftime('%Y-%m-%dT%H:%M'),
          'passengers':'3','notes':'Дві валізи'}
    return {**data,**extra}

def main():
    a,b=Session(),Session()
    check('Home page',a.request('/')[0]==200)
    check('Protected cabinet',a.request('/?page=orders')[0]==401)
    check('CSRF required',a.request('/?page=register',{'action':'register'})[0]==403)
    code,_,_=a.post('/?page=register','register',full_name='Клієнт',email='invalid',phone='+380671234567',password=PASSWORD,password_confirmation=PASSWORD,consent='1')
    check('Invalid email rejected',code==422)
    check('Client registration',registration(a,EMAILS[0])[0]==303)
    check('Duplicate email rejected',registration(Session(),EMAILS[0])[0]==422)
    check('Unverified client cannot order',a.post('/?page=new','save_order',**order_data())[0]==403)
    check('Resend cooldown',a.post('/?page=dashboard','resend')[0]==429)
    link=latest_link(EMAILS[0],'Підтвердження email')
    check('Verification GET only previews',a.request(link)[0]==200 and sql("SELECT email_verified_at IS NULL FROM clients WHERE email='"+EMAILS[0]+"';")=='1')
    raw=urllib.parse.parse_qs(urllib.parse.urlparse(link).query)['token'][0]
    check('Verification POST',a.post(link,'confirm',token=raw)[0]==303)
    check('Token cannot be reused',a.post(link,'confirm',token=raw)[0]==422)
    check('Profile update',a.post('/?page=profile','profile',full_name='Клієнт Після Зміни',phone='+380501234567')[0]==303 and 'Клієнт Після Зміни' in a.request('/?page=profile')[1])
    code,html,_=a.post('/?page=new','save_order',**order_data(destination='Київ, вокзал'))
    check('Identical route rejected and service options preserved',code==422 and 'Міжміська поїздка' in html)
    check('Capacity validated on server',a.post('/?page=new','save_order',**order_data(passengers='9'))[0]==422)
    check('Past departure rejected',a.post('/?page=new','save_order',**order_data(departure_at='2020-01-01T12:00'))[0]==422)
    check('Fractional passengers rejected',a.post('/?page=new','save_order',**order_data(passengers='2.5'))[0]==422)
    check('Create order and queue email',a.post('/?page=new','save_order',**order_data())[0]==303)
    oid=sql("SELECT o.id FROM orders o JOIN clients c ON c.id=o.client_id WHERE c.email='"+EMAILS[0]+"' ORDER BY o.id DESC LIMIT 1;")
    olink=latest_link(EMAILS[0],'Підтвердження замовлення')
    oraw=urllib.parse.parse_qs(urllib.parse.urlparse(olink).query)['token'][0]
    check('Order awaits email',sql('SELECT status FROM orders WHERE id='+oid)== 'awaiting_email')
    check('Second client registration',registration(b,EMAILS[1])[0]==303)
    blink=latest_link(EMAILS[1],'Підтвердження email'); braw=urllib.parse.parse_qs(urllib.parse.urlparse(blink).query)['token'][0]
    b.post(blink,'confirm',token=braw)
    check('Foreign order hidden',b.request('/?page=new&id='+oid)[0]==404)
    check('Foreign order cannot be updated',b.post('/?page=new','save_order',**order_data(id=oid))[0]==404)
    check('Foreign order cannot be cancelled',b.post('/?page=orders','cancel',id=oid)[0]==404)
    check('Foreign order cannot be deleted',b.post('/?page=orders','delete',id=oid)[0]==404)
    x,y=Session(),Session(); cx=x.token(olink);cy=y.token(olink)
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        futures=[pool.submit(s.request,olink,{'action':'confirm','csrf':csrf,'token':oraw}) for s,csrf in [(x,cx),(y,cy)]]
        codes=sorted(f.result()[0] for f in futures)
    check('Concurrent confirmation is single use',codes==[303,422],str(codes))
    check('Confirmed order persisted',sql('SELECT status FROM orders WHERE id='+oid)=='confirmed')
    notes='<script>alert("xss")</script>'
    check('Edit resets confirmation',a.post('/?page=new&id='+oid,'save_order',**order_data(id=oid,notes=notes))[0]==303 and sql('SELECT status FROM orders WHERE id='+oid)=='awaiting_email')
    check('Old order link invalidated',a.request(olink)[0]==422)
    code,bad_form,_=a.post('/?page=new&id='+oid,'save_order',**order_data(id=oid,destination='Київ, вокзал'))
    check('Failed edit preserves order id and form data',code==422 and ('name="id" value="'+oid+'"') in bad_form and 'value="Київ, вокзал"' in bad_form)
    html=a.request('/?page=orders')[1]
    check('HTML escaped', '&lt;script&gt;' in html and notes not in html)
    latest=latest_link(EMAILS[0],'Підтвердження замовлення'); lraw=urllib.parse.parse_qs(urllib.parse.urlparse(latest).query)['token'][0]
    sql("UPDATE email_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE order_id="+oid+" AND used_at IS NULL;")
    check('Expired token rejected',a.request(latest)[0]==422)
    check('Active order cannot be deleted',a.post('/?page=orders','delete',id=oid)[0]==422)
    check('Cancel order',a.post('/?page=orders','cancel',id=oid)[0]==303 and sql('SELECT status FROM orders WHERE id='+oid)=='cancelled')
    check('Cancelled order cannot be edited',a.post('/?page=new&id='+oid,'save_order',**order_data(id=oid))[0]==422)
    check('Delete cancelled order',a.post('/?page=orders','delete',id=oid)[0]==303 and sql('SELECT COUNT(*) FROM orders WHERE id='+oid)=='0')
    check('Unknown route is 404',a.request('/?page=missing')[0]==404)
    check('Logout',a.post('/?page=dashboard','logout')[0]==303 and a.request('/?page=orders')[0]==401)
    for _ in range(5): b.post('/?page=login','login',email=EMAILS[1],password='wrong-password')
    check('Login rate limit',b.post('/?page=login','login',email=EMAILS[1],password=PASSWORD)[0]==429)
    check('Login with stored password hash',a.post('/?page=login','login',email=EMAILS[0],password=PASSWORD)[0]==303)
    # Outbox failure and retry against this project's local SMTP sink.
    subprocess.run(['docker','compose','stop','mailpit'],check=True,capture_output=True)
    try:
        code,_,_=a.post('/?page=new','save_order',**order_data())
        check('SMTP outage preserves order and pending job',code==303 and int(sql("SELECT COUNT(*) FROM mail_jobs m JOIN clients c ON c.id=m.client_id WHERE c.email='"+EMAILS[0]+"' AND m.status='pending'"))>0)
    finally: subprocess.run(['docker','compose','start','mailpit'],check=True,capture_output=True)
    for _ in range(20):
        try: urllib.request.urlopen(MAIL+'/api/v1/info',timeout=2).close(); break
        except Exception: time.sleep(.2)
    check('SMTP retry delivers queued mail',a.post('/?page=dashboard','retry_mail')[0]==303 and sql("SELECT COUNT(*) FROM mail_jobs m JOIN clients c ON c.id=m.client_id WHERE c.email='"+EMAILS[0]+"' AND m.status='pending'")=='0')

if __name__=='__main__':
    try: main()
    finally:
        # Own test users only, identified by the per-run exact email addresses.
        emails=','.join("'"+email+"'" for email in EMAILS)
        ids=sql('SELECT id FROM clients WHERE email IN ('+emails+')')
        for cid in ids.splitlines():
            sql('DELETE FROM orders WHERE client_id='+cid+'; DELETE FROM clients WHERE id='+cid+';')
        Path('test-results').mkdir(exist_ok=True)
        Path('test-results/integration.json').write_text(json.dumps(RESULTS,ensure_ascii=False,indent=2))
        print(str(sum(r['passed'] for r in RESULTS))+'/'+str(len(RESULTS))+' passed')
