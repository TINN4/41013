/* ==========================================================================
   charts.js — Chart.js wrappers with unified LSMS styling
   All charts auto-destroy on re-render to prevent canvas reuse errors.
   ========================================================================== */

const chartRegistry = new Map();

export function destroyChart(id) {
  if (chartRegistry.has(id)) { chartRegistry.get(id).destroy(); chartRegistry.delete(id); }
}

export function destroyAll() {
  chartRegistry.forEach(c => c.destroy());
  chartRegistry.clear();
}

function baseOpts(extra = {}) {
  return {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: true, labels: { font: { family: 'Inter', size: 12 }, color: '#64748b', usePointStyle: true, pointStyle: 'circle', padding: 16 } },
      tooltip: { backgroundColor:'#0f172a', titleFont:{family:'Inter'}, bodyFont:{family:'Inter'}, padding:12, cornerRadius:8, usePointStyle:true }
    },
    ...extra
  };
}

export function lineChart(id, labels, datasets, opts = {}) {
  destroyChart(id);
  const el = document.getElementById(id);
  if (!el) return null;
  const chart = new Chart(el, {
    type: 'line',
    data: { labels, datasets: datasets.map(d => ({ tension:.4, fill:true, borderWidth:2, pointRadius:3, pointHoverRadius:5, ...d })) },
    options: baseOpts({
      scales: {
        x: { grid: { display:false }, ticks: { color:'#94a3b8', font:{family:'Inter',size:11} } },
        y: { grid: { color:'#f1f5f9' }, ticks: { color:'#94a3b8', font:{family:'Inter',size:11} }, beginAtZero: true }
      },
      ...opts
    })
  });
  chartRegistry.set(id, chart);
  return chart;
}

export function barChart(id, labels, datasets, opts = {}) {
  destroyChart(id);
  const el = document.getElementById(id);
  if (!el) return null;
  const chart = new Chart(el, {
    type: 'bar',
    data: { labels, datasets: datasets.map(d => ({ borderRadius:6, borderSkipped:false, maxBarThickness:48, ...d })) },
    options: baseOpts({
      scales: {
        x: { grid: { display:false }, ticks: { color:'#94a3b8', font:{family:'Inter',size:11} } },
        y: { grid: { color:'#f1f5f9' }, ticks: { color:'#94a3b8', font:{family:'Inter',size:11} }, beginAtZero: true }
      },
      ...opts
    })
  });
  chartRegistry.set(id, chart);
  return chart;
}

export function doughnutChart(id, labels, data, colors, opts = {}) {
  destroyChart(id);
  const el = document.getElementById(id);
  if (!el) return null;
  const chart = new Chart(el, {
    type: 'doughnut',
    data: { labels, datasets: [{ data, backgroundColor: colors, borderWidth:0, hoverOffset:8 }] },
    options: baseOpts({ cutout:'65%', plugins:{ legend:{ position:'right', labels:{ font:{family:'Inter',size:11}, color:'#64748b', usePointStyle:true, padding:12 } }, tooltip: baseOpts().plugins.tooltip }, ...opts })
  });
  chartRegistry.set(id, chart);
  return chart;
}

export function radarChart(id, labels, datasets, opts = {}) {
  destroyChart(id);
  const el = document.getElementById(id);
  if (!el) return null;
  const chart = new Chart(el, {
    type: 'radar',
    data: { labels, datasets: datasets.map(d => ({ fill:true, borderWidth:2, pointRadius:3, ...d })) },
    options: baseOpts({
      scales: { r: { beginAtZero:true, max:100, grid:{ color:'#e2e8f0' }, angleLines:{ color:'#e2e8f0' }, pointLabels:{ font:{family:'Inter',size:11}, color:'#64748b' }, ticks:{ display:false } } },
      plugins:{ legend:{ position:'bottom', labels:{ font:{family:'Inter',size:11}, color:'#64748b', usePointStyle:true, padding:12 } }, tooltip: baseOpts().plugins.tooltip },
      ...opts
    })
  });
  chartRegistry.set(id, chart);
  return chart;
}

export function horizontalBarChart(id, labels, data, color = '#2563eb', opts = {}) {
  destroyChart(id);
  const el = document.getElementById(id);
  if (!el) return null;
  const chart = new Chart(el, {
    type: 'bar',
    data: { labels, datasets: [{ data, backgroundColor: color, borderRadius:6, barThickness:18 }] },
    options: baseOpts({
      indexAxis: 'y',
      plugins: { legend: { display:false }, tooltip: baseOpts().plugins.tooltip },
      scales: {
        x: { grid:{ color:'#f1f5f9' }, ticks:{ color:'#94a3b8', font:{family:'Inter',size:11} }, beginAtZero:true },
        y: { grid:{ display:false }, ticks:{ color:'#64748b', font:{family:'Inter',size:11} } }
      },
      ...opts
    })
  });
  chartRegistry.set(id, chart);
  return chart;
}

/* Color palettes */
export const PALETTE = ['#2563eb','#059669','#d97706','#dc2626','#7c3aed','#0891b2','#db2777','#65a30d','#ea580c','#4f46e5'];
export const STATUS_COLORS = { 'Enacted':'#059669','Approved':'#2563eb','Pending Review':'#d97706','Committee Review':'#7c3aed','Drafting':'#94a3b8','Published':'#10b981','Adopted':'#2563eb' };
