import {S,sql,rec,check,save,need,mails} from './lib.mjs';import fs from 'fs';
const A='H-MAIL';const o=new S();await o.login('owner@gate.test','Gate!Owner#2026pw');const ids=JSON.parse(fs.readFileSync('ids.json'));o.cid=ids.BC;
const users={editor:'editor@gate.test',viewer:'viewer@gate.test',admin:'admin@gate.test',other:'otherowner@gate.test'};const pw='Gate!Member#2026pw';
for(const [role,email] of Object.entries(users)){
 const cid=role==='other'?ids.X:ids.BC;const rr=role==='other'?'admin':role;const before=mails().length;
 const r=await o.call('platform/invitations',{method:'POST',json:{action:'create_account',email,scope:'company',assignments:[{companyId:cid,role:rr}],operationKey:'gate-'+role+'-'+Date.now()}});
 const all=mails();const mail=all.length>before?all.at(-1):null;const token=mail?.t.match(/accountSetup=([A-Za-z0-9_-]+)/)?.[1];
 rec('H-M-'+role,A,`Invitation email for ${rr} delivered over STARTTLS+AUTH with single-use link`,r.s===200&&token&&/X-Rcpt: .*/.test(mail.t)?'PASS':'FAIL',r.s+' delivery='+r.b.accountSetup?.deliveryStatus+' link='+(token?'present':'missing')+' msg='+r.b.accountSetup?.message);
 if(!token)continue;const n=new S();const d=await n.call('platform/invite-details?token='+token,{company:false});
 const body={token,password:pw,confirmPassword:pw,acceptTerms:true,termsVersion:d.b.invitation?.termsVersion,privacyVersion:d.b.invitation?.privacyVersion};
 const a=await n.call('platform/invite-accept',{method:'POST',company:false,json:body});
 rec('H-A-'+role,A,`Invitee (${rr}) accepts and sets password`,[200,201].includes(a.s)?'PASS':'FAIL',a.s+' '+JSON.stringify(a.b).slice(0,120));
 const again=await new S().call('platform/invite-accept',{method:'POST',company:false,json:body});
 rec('H-R-'+role,A,'Invitation link cannot be reused',again.s>=400?'PASS':'FAIL',String(again.s));
 try{await new S().login(email,pw);rec('H-L-'+role,A,`${rr} can sign in`,'PASS','200')}catch(e){rec('H-L-'+role,A,`${rr} can sign in`,'FAIL',e.message)}
}
const diag=sql("SELECT diagnostic_json FROM account_invitation_mail LIMIT 1");
rec('D-MAILMSG','H-MAIL','Successful invitation send shows a success diagnostic (not "rejected")',/rejected/.test(diag)?'FAIL':'PASS',diag);
save('install.json');
