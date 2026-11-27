// R187: the screens whose menu moved, captured at their new place but saved under the old file names, so they compare with earlier captures.
import {open} from '../e2e/base.mjs';
import fs from 'fs';
const OUT = process.argv[2]; fs.mkdirSync(OUT + '/screens', {recursive: true});
const pick = process.env.PICK.split(',');
const pages = [['General ledger', 'Journal Entries', 'General ledger', 'Journal Entry'], ['General ledger', 'GIFI Report', 'Reports', 'GIFI Report']];
for (const cid of pick) {
  const {b, p} = await open(cid);
  for (const [mod, item, m2, i2] of pages) {
    try {
      await p.evaluate(([m, i]) => { document.querySelectorAll('.srp-page').forEach(x => x.dataset.capOld = '1'); TeghPortal.invokeMenuAction(m, i); }, [m2, i2]);
      await p.waitForTimeout(3500);
      const text = await p.evaluate(() => { const pg = [...document.querySelectorAll('.srp-page')].filter(x => !x.dataset.capOld).pop() || document.querySelector('main'); return (pg?.innerText || '').trim(); });
      fs.writeFileSync(`${OUT}/screens/${cid}.${(mod + '-' + item).replace(/[^A-Za-z0-9]+/g, '-')}.txt`, text);
    } catch (e) { fs.writeFileSync(`${OUT}/screens/${cid}.${(mod + '-' + item).replace(/[^A-Za-z0-9]+/g, '-')}.txt`, 'CAPTURE ERROR ' + e.message.slice(0, 200)); }
  }
  await b.close();
}
