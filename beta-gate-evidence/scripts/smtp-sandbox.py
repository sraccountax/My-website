import socketserver,os,time,sys,ssl,base64
OUT=sys.argv[1]; CERT,KEY=sys.argv[2],sys.argv[3]; USER,PW=sys.argv[4],sys.argv[5]
ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.load_cert_chain(CERT,KEY)
class H(socketserver.StreamRequestHandler):
    def w(self,s): self.wfile.write((s+"\r\n").encode()); self.wfile.flush()
    def handle(self):
        sock=self.connection; tls=False; authed=False
        rf=sock.makefile('rb'); 
        def w(s): sock.sendall((s+"\r\n").encode())
        w("220 gate-sandbox ESMTP"); data=None; rcpt=[]; mfrom=''
        while True:
            line=rf.readline()
            if not line: return
            cmd=line.decode(errors='replace').rstrip('\r\n'); u=cmd.upper()
            if data is not None:
                if cmd=='.':
                    fn=os.path.join(OUT,f"{time.time():.6f}.eml")
                    open(fn,'w').write("X-Sandbox-Tls: %s\nX-Sandbox-Auth: %s\nX-Rcpt: %s\n"%(tls,authed,",".join(rcpt))+"\n".join(data))
                    data=None; rcpt=[]; w("250 2.0.0 queued"); continue
                data.append(cmd[1:] if cmd.startswith('..') else cmd); continue
            if u.startswith('EHLO') or u.startswith('HELO'):
                sock.sendall(b"250-gate-sandbox\r\n"+(b"250-AUTH LOGIN\r\n" if tls else b"250-STARTTLS\r\n")+b"250 8BITMIME\r\n")
            elif u=='STARTTLS':
                w("220 go"); sock=ctx.wrap_socket(sock,server_side=True); rf=sock.makefile('rb'); tls=True
            elif u=='AUTH LOGIN':
                if not tls: w("530 tls required"); continue
                w("334 VXNlcm5hbWU6"); us=base64.b64decode(rf.readline().strip()).decode()
                w("334 UGFzc3dvcmQ6"); pw=base64.b64decode(rf.readline().strip()).decode()
                if us==USER and pw==PW: authed=True; w("235 ok")
                else: w("535 bad credentials")
            elif u.startswith('MAIL FROM'):
                if not authed: w("530 auth required"); continue
                w("250 ok")
            elif u.startswith('RCPT TO'): rcpt.append(cmd.split(':',1)[1].strip('<> ')); w("250 ok")
            elif u=='DATA': data=[]; w("354 go")
            elif u=='RSET': data=None; rcpt=[]; w("250 ok")
            elif u=='NOOP': w("250 ok")
            elif u=='QUIT': w("221 bye"); return
            else: w("502 unsupported")
class S(socketserver.ThreadingMixIn,socketserver.TCPServer): allow_reuse_address=True; daemon_threads=True
S(('127.0.0.1',2587),H).serve_forever()
