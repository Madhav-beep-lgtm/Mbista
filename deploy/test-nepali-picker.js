const fs = require('fs'), vm = require('vm'), assert = require('assert');
function element(tag = 'input') {
  return {tag, value:'', type:'date', className:'', style:{cssText:''}, attrs:{}, dataset:{}, children:[], listeners:{}, validity:{valid:true}, offsetWidth:310, offsetHeight:300,
    setAttribute(k,v){this.attrs[k]=v;}, getAttribute(k){return this.attrs[k] || null;}, hasAttribute(k){return k in this.attrs;},
    addEventListener(k,fn){this.listeners[k]=fn;}, dispatchEvent(event){event.target ||= this;this.listeners[event.type]?.(event);},
    setCustomValidity(msg){this.validity.valid=!msg;}, appendChild(node){this.children.push(node);}, insertAdjacentElement(position,node){this.next=node;},
    showPopover(){this.open=true;}, hidePopover(){this.open=false;}, matches(){return !!this.open;},
    getBoundingClientRect(){return {left:10,bottom:50};}, querySelector(){return {focus(){}};}, focus(){}, closest(){return this;}
  };
}
for (const mode of ['ad','bs']) {
  const input=element(); input.value='2026-10-13';input.min='2026-10-01'; input.max='2026-10-31';input.required=true;
  let ready;
  const context={window:{innerWidth:1000,innerHeight:800},Date,setTimeout,Event:class{constructor(type){this.type=type;}},
    document:{body:{getAttribute(){return mode;}},createElement:element,addEventListener(k,fn){ready=fn;},querySelectorAll(selector){return selector==='input[type="date"]'?[input]:[];}}};
  vm.runInNewContext(fs.readFileSync('public_html/assets/js/nepali-date.js','utf8'),context);ready();
  if(mode==='ad'){assert.equal(input.next,undefined);continue;}
  const [picker,toggle,calendar]=input.next.children;
  assert.equal(picker.value,'2083-06-27');
  toggle.dispatchEvent({type:'click'});assert.equal(calendar.open,true);
  assert.ok(calendar.innerHTML.includes('Ashwin'));assert.ok(calendar.innerHTML.includes('data-day="27"'));
  assert.ok(calendar.innerHTML.includes(' disabled'));
  const day=element('button');day.setAttribute('data-day','28');day.dataset.day='28';
  calendar.dispatchEvent({type:'click',target:day});assert.equal(input.value,'2026-10-14');assert.equal(picker.value,'2083-06-28');assert.equal(calendar.open,false);
  const clear=element('button');clear.setAttribute('data-clear','');calendar.dispatchEvent({type:'click',target:clear});assert.equal(input.value,'');assert.equal(picker.value,'');
  picker.value='2083-13-40';picker.dispatchEvent({type:'input'});assert.equal(picker.validity.valid,false);
}
console.log('Nepali picker opening, date selection, AD conversion, range rendering, clearing and invalid-input tests passed.');
