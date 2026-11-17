run(){ node ui-sweep.mjs "$@" 2>&1 | head -40; }
setp(){ node ui-sweep.mjs 1440 900 1 $1 $2 $3 set owner@gate.test 'Gate!Owner#2026pw' 1; }
for theme in light dark; do
  setp $theme side full
  run 1920 1080 1 $theme side full $theme-1920 & run 1366 768 1 $theme side full $theme-1366 & run 1440 900 1 $theme side full $theme-1440 & wait
  run 768 1024 1 $theme side full $theme-768 & run 390 844 1 $theme side full $theme-390 & run 360 800 1 $theme side full $theme-360 & wait
  run 320 640 1 $theme side full $theme-320
done
setp light side full
run 1366 768 1.25 light side full zoom125 & run 1366 768 1.5 light side full zoom150 & run 1366 768 2 light side full zoom200 & wait
setp dark top guided
run 1440 900 1 dark top guided top-guided-1440 & run 390 844 1 dark top guided top-guided-390 & wait
setp light top full
run 1366 768 1 light top full top-full-1366 & wait
setp light side full
