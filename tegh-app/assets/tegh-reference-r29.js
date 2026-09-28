/* Tegh R29: reference-led task layouts for high-frequency accounting work.
 * This module only rearranges the real controls created by the portal. It does
 * not clone editable fields, financial values, or mutation handlers.
 */
(() => {
  'use strict';
  if (window.TeghReferenceR29) return;

  const $=(selector,root=document)=>root.querySelector(selector);
  const $$=(selector,root=document)=>[...root.querySelectorAll(selector)];
  const element=(tag,className,text)=>{const node=document.createElement(tag);if(className)node.className=className;if(text!==undefined)node.textContent=text;return node;};
  const direct=(root,selector)=>[...root.children].find(node=>node.matches?.(selector))||null;
  const field=(form,name)=>form.elements?.namedItem(name)||$(`[name="${name}"]`,form);
  const money=(value,currency='CAD')=>{const number=Number(value);try{return Number.isFinite(number)?new Intl.NumberFormat('en-CA',{style:'currency',currency:String(currency||'CAD').toUpperCase()}).format(number):'—';}catch{return Number.isFinite(number)?number.toFixed(2):'—';}};
  const definitionRow=(term,value,className='')=>{const row=element('div',className),dt=element('dt','',term),dd=element('dd','',value);row.append(dt,dd);return row;};
  const setDefinitionList=(list,rows)=>list.replaceChildren(...rows.map(row=>definitionRow(row[0],row[1],row[2]||'')));

  function pageTitle(page,title,subtitle=''){
    const heading=$('.tegh-page-head h1,.srp-page-head h1',page);if(heading)heading.textContent=title;
    const copy=$('.tegh-page-head .r22-title>p,.srp-page-head>div>p',page);if(copy&&subtitle)copy.textContent=subtitle;
  }

  function breadcrumb(page,section,current){
    const title=$('.tegh-page-head .r22-title,.srp-page-head>div',page);if(!title||$('.r29-breadcrumb',title))return;
    const nav=element('nav','r29-breadcrumb');nav.setAttribute('aria-label','Breadcrumb');nav.innerHTML=`<span>${section}</span><i aria-hidden="true">/</i><b>${current}</b>`;title.prepend(nav);
  }

  function setLegend(fieldset,text){const legend=direct(fieldset,'legend');if(legend)legend.textContent=text;}

  function disclosure(summary,nodes){
    const details=element('details','r29-form-disclosure'),label=element('summary','',summary);details.append(label);
    for(const node of nodes.filter(Boolean))details.append(node);
    return details;
  }

  function enhanceParty(page,kind){
    const form=$(kind==='customer'?'[data-customer-form]':'[data-vendor-form]',page);if(!form||form.dataset.r29Reference)return;
    form.dataset.r29Reference=kind;page.dataset.r29Reference=kind;form.classList.add('r29-party-form');
    const isCustomer=kind==='customer';pageTitle(page,isCustomer?'New Customer':'New Vendor',isCustomer?'Add billing, contact and payment defaults for this customer.':'Add contact, payment and accounting defaults for this vendor.');
    breadcrumb(page,isCustomer?'Receivables':'Payables',isCustomer?'Customers':'Vendors');
    const sets=$$('fieldset.tegh-form-section',form),actions=direct(form,'.srp-actions,.form-actions');if(sets.length<3)return;
    setLegend(sets[0],isCustomer?'Customer details':'Vendor details');setLegend(sets[1],isCustomer?'Billing address':'Business address');setLegend(sets[2],isCustomer?'Payment preferences':'Payment & accounting');
    const layout=element('div','r29-party-layout'),left=element('section','r29-form-column r29-form-primary'),right=element('aside','r29-form-column r29-form-secondary');
    form.insertBefore(layout,sets[0]);layout.append(left,right);left.append(sets[0],sets[1]);right.append(sets[2]);
    const status=field(form,'status')?.closest('label');if(status){const statusCard=element('section','r29-status-card'),heading=element('h3','', 'Status');status.before(statusCard);statusCard.append(heading,status);}
    const opening=['openingBalance','openingBalanceDate'].map(name=>field(form,name)?.closest('label')).filter(Boolean);
    if(opening.length)right.append(disclosure('Opening balance',opening));
    const notes=sets[3];if(notes)right.append(disclosure('Internal notes',[notes]));
    if(actions){actions.classList.add('r29-sticky-actions');actions.querySelector('button[type="submit"]')?.replaceChildren(document.createTextNode(isCustomer?'Create customer':'Create vendor'));form.append(actions);}
  }

  function enhanceProduct(page){
    const editor=$('[data-r22-product-editor]:not([hidden])',page),form=$('#srp-product-form',editor||page);if(!editor||!form)return;
    const editing=!!field(form,'id')?.value;
    pageTitle(page,editing?'Edit Product or Service':'New Product or Service',editing?'Update this item and its sales income account for future invoices.':'Create a reusable item with a sales income account.');breadcrumb(page,page.dataset.srpModule==='Payables'?'Payables':'Receivables','Products & services');
    if(form.dataset.r29Reference){const save=$('button[type="submit"]',form);if(save)save.textContent=editing?'Save changes':'Create item';return;}
    form.dataset.r29Reference='product';page.dataset.r29Reference='product';editor.classList.add('r29-product-editor');
    direct(editor,'h2')?.remove();
    const layout=element('div','r29-product-layout'),main=element('section','r29-product-form-card'),preview=element('aside','r29-product-preview');
    form.before(layout);layout.append(main,preview);main.append(form);
    const kind=field(form,'kind'),segment=element('div','r29-segmented');segment.setAttribute('aria-label','Item type');
    for(const [value,label] of [['service','Service'],['product','Product']]){const button=element('button','',label);button.type='button';button.dataset.value=value;button.onclick=()=>{kind.value=value;kind.dispatchEvent(new Event('change',{bubbles:true}));sync();};segment.append(button);}
    const kindLabel=kind.closest('label');kindLabel?.after(segment);if(kindLabel)kindLabel.hidden=true;
    const actions=direct(form,'.srp-actions');if(actions){actions.classList.add('r29-sticky-actions');const save=$('button[type="submit"]',actions);if(save)save.textContent=editing?'Save changes':'Create item';}
    const previewHeader=element('header'),previewEyebrow=element('small','','Invoice line preview'),previewName=element('h2'),previewType=element('span'),previewDescription=element('p'),previewList=element('dl');previewHeader.append(previewEyebrow,previewName,previewType);preview.append(previewHeader,previewDescription,previewList);
    const sync=()=>{const name=field(form,'name')?.value.trim()||'New item',description=field(form,'description')?.value.trim()||'Description will appear on invoice lines.',price=money(field(form,'unitPrice')?.value||0),type=kind?.value==='product'?'Product':'Service',code=field(form,'code')?.value.trim()||'No code',account=field(form,'incomeAccountId')?.selectedOptions?.[0]?.textContent?.trim()||'Use default income account',taxable=field(form,'taxable')?.checked;
      $$('button',segment).forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.value===kind?.value)));
      previewName.textContent=name;previewType.textContent=type;previewDescription.textContent=description;setDefinitionList(previewList,[['Code',code],['Unit price',price],['Income account',account],['Tax',taxable?'Taxable':'Non-taxable']]);
    };
    form.addEventListener('input',sync);form.addEventListener('change',sync);sync();
  }

  function invoiceSummary(form,aside){
    const totals=$$('.srp-ci-totals>div',form),template=field(form,'templateId')?.selectedOptions?.[0]?.textContent?.replace(' · Default','').trim()||'Choose a template';
    let header=$(':scope > header',aside);if(!header){header=element('header');header.innerHTML='<small>Preview</small><h2>Invoice summary</h2>';aside.prepend(header);}
    let meta=$(':scope > dl',aside);if(!meta){meta=element('dl');header.after(meta);}
    const amountDue=totals[2]?.querySelector('b')?.textContent||$('.srp-ci-totals .total b',form)?.textContent||'$0.00';
    setDefinitionList(meta,[['Template',template],['Amount due',amountDue,'total']]);
  }

  function productFinder(select){
    if(!select||select.dataset.r63Finder)return;
    select.dataset.r63Finder='1';
    const trigger=element('button','srp-btn secondary r63-find-item','Find product/service');trigger.type='button';const fieldLabel=select.closest('label');if(fieldLabel){const group=element('div','r63-product-field');fieldLabel.before(group);group.append(fieldLabel,trigger)}
    trigger.onclick=()=>{
      const options=[...select.options].filter(option=>option.value&&!option.value.startsWith('__create_'));
      const prior=document.activeElement,dialog=element('dialog','r20-customer-picker r63-item-picker');
      dialog.setAttribute('aria-label','Find product or service');
      const head=element('header'),heading=element('h2','', 'Find product or service'),close=element('button','srp-btn secondary','Close');close.type='button';head.append(heading,close);
      const label=element('label','', 'Search products and services'),search=element('input');search.type='search';search.placeholder='Name or code';label.append(search);
      const list=element('div','r20-customer-picker-list'),empty=element('p','', 'No matching products or services');empty.hidden=true;
      options.forEach(option=>{const button=element('button','',option.textContent.trim());button.type='button';button.onclick=()=>{select.value=option.value;select.dispatchEvent(new Event('change',{bubbles:true}));dialog.close()};list.append(button)});
      const filter=()=>{let shown=0;[...list.children].forEach(button=>{button.hidden=!button.textContent.toLocaleLowerCase().includes(search.value.trim().toLocaleLowerCase());if(!button.hidden)shown++});empty.hidden=!!shown};
      search.oninput=filter;close.onclick=()=>dialog.close();dialog.addEventListener('close',()=>{dialog.remove();prior?.isConnected&&prior.focus()},{once:true});dialog.append(head,label,list,empty);document.body.append(dialog);dialog.showModal();search.focus();
    };
  }

  function enhanceInvoice(page){
    const form=$('[data-ci-form]',page);if(!form||form.dataset.r29Reference)return;
    form.dataset.r29Reference='invoice';page.dataset.r29Reference='invoice';form.classList.add('r29-invoice-form');
    pageTitle(page,'New Invoice','Create, review and issue a customer invoice.');breadcrumb(page,'Receivables','Invoices');
    const title=$('.tegh-page-head .r22-title,.srp-page-head>div',page);if(title&&!$('.r29-draft-pill',title))title.querySelector('h1')?.after(element('span','r29-draft-pill','Draft'));
    const header=$('.r22-invoice-header',form),linesHead=$('.srp-ci-lines-head',form),lines=$('.srp-ci-lines',form),notes=$('.r22-invoice-notes',form)?.closest('details.r22-menu')||$('.r22-invoice-notes',form),footer=$('.r22-document-footer',form)||direct(form,'.srp-actions'),totals=$('.srp-ci-totals',form);
    const layout=element('div','r29-invoice-layout'),main=element('section','r29-invoice-main'),aside=element('aside','r29-invoice-summary');
    form.prepend(layout);layout.append(main,aside);[header,linesHead,lines,notes].filter(Boolean).forEach(node=>main.append(node));invoiceSummary(form,aside);if(totals)aside.append(totals);
    const refresh=()=>invoiceSummary(form,aside);form.addEventListener('input',()=>requestAnimationFrame(refresh));form.addEventListener('change',()=>requestAnimationFrame(refresh));
    const actions=$('.srp-actions',form);if(actions){actions.classList.add('r29-sticky-actions');if(footer?.classList?.contains('r22-document-footer'))footer.append(actions);else form.append(actions);if(!$('[data-r29-cancel-invoice]',actions)){const cancel=element('button','srp-btn secondary','Cancel');cancel.type='button';cancel.dataset.r29CancelInvoice='1';actions.prepend(cancel);}}
    $$('[data-ci-line] select[name="productServiceId"]',form).forEach(productFinder);
  }

  function enhanceBill(page){
    const form=$('[data-bill-form]',page);if(!form||form.dataset.r29Reference)return;
    form.dataset.r29Reference='bill';page.dataset.r29Reference='bill';form.classList.add('r29-invoice-form','r63-bill-form');
    const editing=!!page.querySelector('[data-bill-form] h3')?.textContent.includes('Edit');
    pageTitle(page,editing?'Edit Vendor Invoice':'New Vendor Invoice','Enter vendor details and review invoice lines before saving.');
    breadcrumb(page,'Payables','Vendor invoices');
    const title=$('.tegh-page-head .r22-title,.srp-page-head>div',page);
    if(title&&!$('.r29-draft-pill',title))title.querySelector('h1')?.after(element('span','r29-draft-pill','Draft'));
    const main=element('section','r29-invoice-main'),aside=element('aside','r29-invoice-summary'),layout=element('div','r29-invoice-layout');
    const input=name=>field(form,name),label=name=>input(name)?.closest('label');
    const heading=direct(form,'h3');heading?.remove();
    form.prepend(layout);layout.append(main,aside);
    const header=element('section','r22-invoice-header r63-bill-header');header.setAttribute('aria-label','Vendor and document fields');main.append(header);
    ['vendorId','number','billDate','paymentTermsDays','dueDate','currency'].forEach(name=>{const node=label(name);if(node)header.append(node)});
    const vendor=input('vendorId'),findVendor=element('button','srp-btn secondary r63-find-vendor','Find vendor');findVendor.type='button';vendor?.closest('label')?.after(findVendor);
    findVendor.onclick=()=>{
      const options=[...vendor.options].filter(option=>option.value&&!option.value.startsWith('__create_'));
      const prior=document.activeElement,dialog=element('dialog','r20-customer-picker');dialog.setAttribute('aria-label','Find vendor');
      const head=element('header'),h=element('h2','', 'Find vendor'),close=element('button','srp-btn secondary','Close');close.type='button';head.append(h,close);
      const l=element('label','', 'Search vendors'),search=element('input');search.type='search';l.append(search);const list=element('div','r20-customer-picker-list'),empty=element('p','', 'No vendors match this search');empty.hidden=true;
      options.forEach(option=>{const b=element('button','',option.textContent.trim());b.type='button';b.onclick=()=>{vendor.value=option.value;vendor.dispatchEvent(new Event('change',{bubbles:true}));dialog.close()};list.append(b)});
      search.oninput=()=>{let count=0;[...list.children].forEach(b=>{b.hidden=!b.textContent.toLocaleLowerCase().includes(search.value.trim().toLocaleLowerCase());if(!b.hidden)count++});empty.hidden=!!count};close.onclick=()=>dialog.close();dialog.addEventListener('close',()=>{dialog.remove();prior?.isConnected&&prior.focus()},{once:true});dialog.append(head,l,list,empty);document.body.append(dialog);dialog.showModal();search.focus();
    };
    const lineHead=element('div','srp-ci-section-title srp-ci-lines-head'),lineTitle=element('div'),add=element('button','srp-btn secondary','Add Line');add.type='button';add.dataset.r63AddBillLine='1';lineTitle.innerHTML='<h3>Invoice Lines</h3><p>All lines share the account and tax settings. Multiple lines are itemized in the saved invoice memo.</p>';lineHead.append(lineTitle,add);main.append(lineHead);
    const lines=element('div','srp-ci-lines r63-bill-lines');main.append(lines);
    const first=element('article','srp-ci-line r63-bill-line');first.dataset.billLine='1';first.innerHTML='<header><div><small>Line 1</small><b>Product or service</b></div></header><div class="srp-form srp-ci-line-grid r63-bill-line-grid"></div><div class="srp-ci-line-total"><strong>Line amount <b></b></strong></div>';
    const firstGrid=$('.r63-bill-line-grid',first);[label('productServiceId'),label('quantity')].filter(Boolean).forEach(node=>firstGrid.append(node));
    const firstDescription=element('label','', 'Description'),description=element('input');description.type='text';description.maxLength=120;description.required=true;description.className='r63-bill-description';firstDescription.append(description);firstGrid.insertBefore(firstDescription,firstGrid.children[1]||null);
    const rateLabel=element('label','', 'Rate'),rate=element('input');rate.type='number';rate.min='0.000001';rate.step='0.000001';rate.required=true;rate.className='r63-bill-rate';rateLabel.append(rate);firstGrid.append(rateLabel);lines.append(first);
    const amountLabel=label('amount'),amount=input('amount');amountLabel?.classList.add('r63-bill-total-field');amount.readOnly=true;amountLabel?.querySelector('small')?.remove();
    const summaryHead=element('header');summaryHead.innerHTML='<small>Preview</small><h2>Vendor invoice summary</h2>';aside.append(summaryHead);
    ['categoryAccountId','taxEntryMode','applyGstHst','applyPst'].forEach(name=>{const node=label(name);if(node)aside.append(node)});
    const breakdown=$('[data-tax-breakdown]',form);if(breakdown)aside.append(breakdown);if(amountLabel)aside.append(amountLabel);
    const memoLabel=label('memo');if(memoLabel)main.append(memoLabel);
    const recurring=$('.srp-recurring-choice',form);if(recurring)main.append(recurring);
    const actions=direct(form,'.srp-actions');if(actions){actions.classList.add('r29-sticky-actions');form.append(actions)}
    const productOptions=()=>[...input('productServiceId').options].filter(option=>option.value&&!option.value.startsWith('__create_'));
    const productName=id=>productOptions().find(option=>option.value===id)?.textContent.replace(/^(Product|Service)\s*·\s*/,'').trim()||'';
    const memo=input('memo'),block=/\n?\[Invoice lines\]\n([\s\S]*?)\n\[\/Invoice lines\]/;
    const stored=String(memo.value||'').match(block);if(stored)memo.value=memo.value.replace(block,'').trim();
    const initialQty=Number(input('quantity').value)||1,initialAmount=Number(amount.value)||0;
    description.value=productName(input('productServiceId').value)||'';rate.value=initialAmount?(initialAmount/initialQty).toFixed(6).replace(/0+$/,'').replace(/\.$/,''):'';
    const currency=()=>input('currency').value||'CAD';
    const rows=()=>$$('[data-bill-line]',lines);
    const sync=()=>{let total=0;rows().forEach((row,index)=>{const q=Number($('.r63-bill-quantity',row)?.value||$('input[name="quantity"]',row)?.value||0),r=Number($('.r63-bill-rate',row)?.value||0),sum=Math.round(q*r*100)/100;total+=sum;const name=$('.r63-bill-description',row)?.value.trim()||'Product or service';$('header small',row).textContent=`Line ${index+1}`;$('header b',row).textContent=name;$('.srp-ci-line-total b',row).textContent=money(sum,currency())});amount.value=total>0?total.toFixed(2):'';amount.dispatchEvent(new Event('input',{bubbles:true}));form.dataset.srpDirty='1'};
    const append=(id='',name='',qty='1',price='')=>{const row=element('article','srp-ci-line r63-bill-line');row.dataset.billLine='1';row.innerHTML='<header><div><small></small><b></b></div><button type="button" class="srp-btn secondary" data-remove-bill-line>Remove</button></header><div class="srp-form srp-ci-line-grid r63-bill-line-grid"><label>Product or Service<select class="r63-bill-product"><option value="">Custom invoice line</option></select></label><label>Description<input class="r63-bill-description" maxlength="120" required></label><label>Quantity<input class="r63-bill-quantity" type="number" min="0.001" max="1000000" step="0.001" required></label><label>Rate<input class="r63-bill-rate" type="number" min="0.000001" step="0.000001" required></label></div><div class="srp-ci-line-total"><strong>Line amount <b></b></strong></div>';
      const select=$('select',row);productOptions().forEach(option=>select.append(option.cloneNode(true)));select.value=id;$('.r63-bill-description',row).value=name||productName(id);$('.r63-bill-quantity',row).value=qty;$('.r63-bill-rate',row).value=price;lines.append(row);productFinder(select);select.onchange=()=>{const found=productName(select.value);if(found){$('.r63-bill-description',row).value=found;$('.r63-bill-rate',row).value=select.selectedOptions[0]?.dataset.price||''}sync()};$('[data-remove-bill-line]',row).onclick=()=>{row.remove();sync()};row.addEventListener('input',sync);sync();};
    if(stored){const itemLines=stored[1].split('\n').map(line=>line.match(/^\d+\. (.+) \| ([\d.]+) × ([\d.]+)$/)).filter(Boolean);if(itemLines.length){description.value=itemLines[0][1];input('quantity').value=itemLines[0][2];rate.value=itemLines[0][3];itemLines.slice(1).forEach(match=>append('',match[1],match[2],match[3]))}}
    const firstProduct=input('productServiceId');productFinder(firstProduct);firstProduct.addEventListener('change',()=>{const name=productName(firstProduct.value);if(name){description.value=name;rate.value=firstProduct.selectedOptions[0]?.dataset.price||''}sync()});first.addEventListener('input',sync);add.onclick=()=>{append();$('.r63-bill-line:last-child select',lines)?.focus()};
    form.addEventListener('change',event=>{if(event.target===input('currency'))sync()});
    form.addEventListener('submit',event=>{const entries=rows().map(row=>({description:$('.r63-bill-description',row).value.trim(),quantity:Number($('.r63-bill-quantity',row)?.value||$('input[name="quantity"]',row)?.value||0),rate:Number($('.r63-bill-rate',row).value)}));
      if(entries.some(line=>!line.description||!Number.isFinite(line.quantity)||line.quantity<=0||!Number.isFinite(line.rate)||line.rate<=0)){event.preventDefault();event.stopImmediatePropagation();form.reportValidity();return}
      const note=memo.value.replace(block,'').trim();
      const summary='[Invoice lines]\n'+entries.map((line,index)=>`${index+1}. ${line.description} | ${line.quantity} × ${line.rate.toFixed(6).replace(/0+$/,'').replace(/\.$/,'')}`).join('\n')+'\n[/Invoice lines]';const combined=[note,summary].filter(Boolean).join('\n');if(combined.length>500){event.preventDefault();event.stopImmediatePropagation();memo.setCustomValidity('Invoice lines and memo exceed 500 characters. Shorten descriptions or the memo.');memo.reportValidity();return}memo.setCustomValidity('');memo.value=combined;
    },true);
    memo.addEventListener('input',()=>{memo.setCustomValidity('')});
    sync();form.dataset.srpDirty='0';
  }

  function selectedParts(select){const text=select?.selectedOptions?.[0]?.textContent?.trim()||'No invoice selected',parts=text.split(' · ');return{party:parts[0]||'—',number:parts[1]||'—',open:parts[2]||'—'};}

  function enhancePayment(page,type){
    const form=$('[data-payment-form]',page);if(!form||form.dataset.r29Reference)return;
    const customer=type==='customer';form.dataset.r29Reference=type+'-payment';page.dataset.r29Reference=type+'-payment';form.classList.add('r29-payment-form');
    pageTitle(page,customer?'New Customer Receipt':'New Vendor Payment',customer?'Record a customer receipt and apply it to an open invoice.':'Record a vendor payment and apply it to an open invoice.');breadcrumb(page,customer?'Receivables':'Payables',customer?'Customer receipts':'Vendor payments');
    const card=form.closest('.srp-card');if(!card)return;card.classList.add('r29-payment-card');
    const tabs=element('div','r29-segmented r29-payment-modes');tabs.setAttribute('aria-label','Entry method');
    const manual=element('button','', 'Manual entry'),match=element('button','', 'Match bank transaction');manual.type=match.type='button';tabs.append(manual,match);form.prepend(tabs);
    const bank=field(form,'bankTransactionId'),documentSelect=field(form,'documentId'),flow=field(form,'flowKind'),date=field(form,'paymentDate'),amount=field(form,'amount'),account=field(form,'paymentAccountId'),reference=field(form,'reference'),memo=field(form,'memo'),currency=field(form,'currency'),rate=field(form,'exchangeRate');
    bank?.closest('label')?.classList.add('r29-payment-bank-source');if(flow?.closest('label'))flow.closest('label').childNodes[0].textContent=customer?'Receipt type':'Payment type';
    const detail=element('h3','r29-form-heading','Payment details');date?.closest('label')?.before(detail);
    const optional=[currency?.closest('label'),rate?.closest('label')].filter(Boolean);if(optional.length){const more=disclosure(customer?'Other receipt options':'Other payment options',optional);memo?.closest('label')?.after(more);}
    const aside=element('aside','r29-payment-summary');card.append(aside);
    const summaryHeader=element('header'),summaryEyebrow=element('small','','Selected invoice'),summaryTitle=element('h2','',customer?'Receipt summary':'Payment summary'),summaryNumber=element('strong'),summaryParty=element('p'),summaryList=element('dl');summaryHeader.append(summaryEyebrow,summaryTitle);aside.append(summaryHeader,summaryNumber,summaryParty,summaryList);
    const sync=()=>{const matched=!!bank?.value,parts=selectedParts(documentSelect),currencyCode=currency?.value||'CAD',value=money(amount?.value||0,currencyCode),accountText=account?.selectedOptions?.[0]?.textContent?.trim()||'Choose an account',outstanding=Number(String(parts.open).replace(/[^0-9.-]/g,'')),entered=Number(amount?.value),hasDocument=!!documentSelect?.value,remaining=hasDocument&&Number.isFinite(outstanding)&&Number.isFinite(entered)?money(Math.max(0,outstanding-entered),currencyCode):(hasDocument?'Available after invoice details load':'On account');manual.setAttribute('aria-pressed',String(!matched));match.setAttribute('aria-pressed',String(matched));
      summaryNumber.textContent=parts.number;summaryParty.textContent=parts.party;setDefinitionList(summaryList,[['Invoice outstanding',parts.open],[customer?'Receipt amount':'Payment amount',value],['Remaining balance',remaining,'total'],[customer?'Deposit to':'Paid from',accountText]]);
    };
    manual.onclick=()=>{if(!bank)return;bank.value='';bank.dispatchEvent(new Event('change',{bubbles:true}));sync();};match.onclick=()=>{bank?.focus();bank?.click();};
    form.addEventListener('input',sync);form.addEventListener('change',sync);sync();
    const actions=direct(form,'.srp-actions');if(actions){actions.classList.add('r29-sticky-actions');const submit=$('button[type="submit"]',actions);if(submit)submit.textContent=customer?'Record receipt':'Record payment';}
  }

  function enhanceUpload(page){
    const grid=$('.r28-upload-grid',page);if(!grid||grid.dataset.r29Reference)return;grid.dataset.r29Reference='upload';page.dataset.r29Reference='upload';breadcrumb(page,'Banking','Statements');
    $$('.srp-card',grid).forEach(card=>card.classList.add('r29-upload-card'));
    $$('input[type="file"]',grid).forEach(input=>input.closest('label')?.classList.add('r29-dropzone'));
    const upload=$('[data-bank-preview-form] button[type="submit"]',grid);if(upload)upload.textContent='Upload and Preview';
    const convert=$('[data-converter-form] button[type="submit"]',grid);if(convert)convert.textContent='Convert and Review';
  }

  function enhanceBankReport(page){
    if(page.dataset.r29Reference)return;const report=$('.r28-bank-transaction-report,.r23-table-host',page);if(!report)return;
    page.dataset.r29Reference='bank-report';breadcrumb(page,'Banking','Reports');
    const search=$('.r28-bank-report-search input',page);if(search)search.placeholder='Search transactions';
  }

  function enhance(page){
    if(!page?.isConnected)return;switch(page.dataset.srpPage){
      case 'customer-invoice':enhanceInvoice(page);break;
      case 'bills':enhanceBill(page);break;
      case 'customers':enhanceParty(page,'customer');break;
      case 'vendors':enhanceParty(page,'vendor');break;
      case 'products':enhanceProduct(page);break;
      case 'customer-payments':enhancePayment(page,'customer');break;
      case 'vendor-payments':enhancePayment(page,'vendor');break;
      case 'bank-imports':enhanceUpload(page);break;
      case 'report-bank-transactions':enhanceBankReport(page);break;
    }
  }

  let frame=0;
  const schedule=()=>{if(frame)return;frame=requestAnimationFrame(()=>{frame=0;enhance($('.srp-page'));});};
  document.addEventListener('click',event=>{
    if(!event.target.closest?.('[data-r29-cancel-invoice]'))return;
    event.preventDefault();
    document.querySelector('main[data-r29-reference="invoice"] .r22-return')?.click();
  },true);
  window.addEventListener('tegh:page-rendered',event=>{requestAnimationFrame(()=>enhance(event.detail?.page||$('.srp-page')));});
  const observer=new MutationObserver(schedule);observer.observe(document.documentElement,{subtree:true,childList:true});
  document.addEventListener('change',schedule,true);document.addEventListener('input',schedule,true);
  window.TeghReferenceR29=Object.freeze({refresh:()=>enhance($('.srp-page'))});
  schedule();
})();
