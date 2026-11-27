import shutil
p = '/srv/gate/www/assets/tegh-portal-v5990.js'
shutil.copy(p, '/srv/gate/t/perf/portal.orig.js')
s = open(p).read()
old = "  function invalidateClientReads(){\n"
assert old in s
s = s.replace(old, old + "    (window.__inv=window.__inv||[]).push('inv '+performance.now().toFixed(0)+' '+(new Error().stack||'').split('\\n').slice(2,6).map(x=>x.trim().replace(/https:\\/\\/gate.test\\/assets\\//,'').replace(/\\?v=[^:]*/,'')).join(' < '));\n", 1)
open(p, 'w').write(s)
print('ok')
