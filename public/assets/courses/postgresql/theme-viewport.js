/* LD Academy: controlador isolado do layout PostgreSQL. */
(() => {
 'use strict';
 const root=document.documentElement;
 const key='ldacademy:postgresql:theme';
 const applyTheme=(theme)=>{
  const dark=theme==='dark';root.dataset.pgTheme=dark?'dark':'light';
  const button=document.getElementById('pg-theme-toggle');if(!button)return;
  button.setAttribute('aria-pressed',String(dark));
  button.setAttribute('aria-label',dark?'Ativar modo claro':'Ativar modo escuro');
  const icon=button.querySelector('[data-pg-theme-icon]');
  const label=button.querySelector('[data-pg-theme-label]');
  if(icon)icon.textContent=dark?'☼':'☾';
  if(label)label.textContent=dark?'Modo claro':'Modo escuro';
 };
 document.addEventListener('DOMContentLoaded',()=>{
  applyTheme(root.dataset.pgTheme);
  const toggle=document.getElementById('pg-theme-toggle');
  if(toggle)toggle.addEventListener('click',()=>{
   const value=root.dataset.pgTheme==='dark'?'light':'dark';
   applyTheme(value);
   try{localStorage.setItem(key,value)}catch(_){}
  });
  if(!document.body.classList.contains('pg-is-home'))return;
  const grid=document.querySelector('[data-pg-module-grid]');
  const cards=grid?Array.from(grid.querySelectorAll('[data-pg-module-card]')):[];
  const pager=document.querySelector('[data-pg-pager]');
  const prev=document.getElementById('pg-modules-prev');
  const next=document.getElementById('pg-modules-next');
  const counter=document.getElementById('pg-modules-counter');
  if(!grid||!pager||!prev||!next||!counter||!cards.length)return;
  let page=0;
  const pageSize=()=>innerWidth<700?1:innerWidth<1100?2:3;
  let oldSize=pageSize();
  const render=(focus)=>{
   const per=pageSize();
   const total=Math.ceil(cards.length/per);
   page=Math.max(0,Math.min(page,total-1));
   const start=page*per,end=Math.min(start+per,cards.length);
   for(let i=0;i<cards.length;i++)cards[i].hidden=i<start||i>=end;
   prev.disabled=page===0;
   next.disabled=page===total-1;
   counter.textContent='Exibindo '+(start+1)+'–'+end+' de '+cards.length+' módulos';
   pager.hidden=total===1;
   if(focus&&cards[start]){
    cards[start].setAttribute('tabindex','-1');
    cards[start].focus({preventScroll:true});
   }
  };
  prev.addEventListener('click',()=>{page--;render(true)});
  next.addEventListener('click',()=>{page++;render(true)});
  const viewModules=document.querySelector('.pg-hero-actions a[href="#modulos"]');
  if(viewModules)viewModules.addEventListener('click',(event)=>{
   event.preventDefault();
   const header=document.querySelector('.pg-learning-head h2');
   if(header){header.setAttribute('tabindex','-1');header.focus({preventScroll:true})}
  });
  addEventListener('resize',()=>{
   const n=pageSize();
   if(n!==oldSize){const first=page*oldSize;page=Math.floor(first/n);oldSize=n;render(false)}
  });
  root.classList.add('pg-js-ready');render(false);
 });
})();
