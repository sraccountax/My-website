import shutil
p = '/srv/gate/www/assets/tegh-activity-r22.js'
shutil.copy(p, '/srv/gate/t/perf/activity.orig.js')
s = open(p).read()
old = """    attachWorkspace(page,s);const c=contract(page);
    simplifyHeadings(s);toolbar(s,c);authoredChrome(s,c);operatorChrome(s,c);finalChrome(s,c);formLayout(s);namedSections(s);arrange(s,c);plainStates(s);fitToolbar(s);
    $$(tableSelector,s.work).forEach(annotateTable);fitForecastCharts(s);"""
new = """    const __T=[];let __t=performance.now();const __m=n=>{const x=performance.now();__T.push(n+':'+Math.round(x-__t));__t=x};
    attachWorkspace(page,s);const c=contract(page);__m('attach');
    simplifyHeadings(s);toolbar(s,c);__m('toolbar');authoredChrome(s,c);operatorChrome(s,c);finalChrome(s,c);__m('chrome');formLayout(s);namedSections(s);__m('form');arrange(s,c);__m('arrange');plainStates(s);fitToolbar(s);__m('fit');
    $$(tableSelector,s.work).forEach(annotateTable);__m('annot');fitForecastCharts(s);(window.__mounts=window.__mounts||[]).push([document.querySelectorAll('*').length,__T.join(' ')]);"""
assert old in s
open(p, 'w').write(s.replace(old, new))
print('instrumented')
