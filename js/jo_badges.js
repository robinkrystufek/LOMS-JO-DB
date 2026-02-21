const foregroundColors = ["rgb(119 119 119)", "#A33A3A", "#8A6D1F", "#2F7A4A"];
const backgroundColors = ["#f8f9fa", "#FDECEC", "#FFF8E1", "#EAF7EF"];
const borderColors = ["#e4e6e5", "#A33A3A", "#8A6D1F", "#2F7A4A"];
const badgeTitles = {
  n:       ["Refractive index: Not searched", "Refractive index: Not available", "Refractive index: Unknown", "Refractive index: Available"],
  cjo:     ["Combinatorial JO analysis: Not searched", "Combinatorial JO analysis: Not performed", "Combinatorial JO analysis: Unknown", "Combinatorial JO analysis: Performed"],
  sigmafs: ["σ,F,S: Not searched", "σ,F,S: Not available", "σ,F,S: Unknown", "σ,F,S: Available"],
  m1:      ["Magnetic dipole correction: Not searched", "Magnetic dipole correction: Not applied", "Magnetic dipole correction: Unknown", "Magnetic dipole correction: Applied"],
  re:      ["Reduced elements: Not searched", "Reduced elements: Not included", "Reduced elements: Unknown", "Reduced elements: Included"],
  loms:    ["Calculation by LOMS: Not searched", "Calculation by LOMS: Not available", "Calculation by LOMS: Unknown", "Calculation by LOMS: Available"],
  density: ["Density: Not searched", "Density: Not available", "Density: Unknown", "Density: Available"]
};
function stateToIndex(state) {
  return state + 1; // -1..2 → 0..3
}
function applyBadgeState(el, state) {
  const idx = stateToIndex(state);
  el.style.color = foregroundColors[idx];
  el.style.backgroundColor = backgroundColors[idx];
  el.style.border = `1px solid ${borderColors[idx]}`;
  const key = el.dataset.badge || "";
  const titles = badgeTitles[key];
  if (titles && titles[idx]) {
    el.title = titles[idx];
  } 
  else {
    const generic = ["Not searched", "No", "Unknown", "Yes"];
    el.title = generic[idx];
  }
  el.dataset.state = String(state);
}
function cycleBadge(el) {
  const current = Number(el.dataset.state ?? -1);
  const next = (current >= 2) ? -1 : current + 1;
  applyBadgeState(el, next);
}
function initBadgeSelector() {
  const container = document.getElementById("badge-selector");
  if (!container) return;
  const badges = container.querySelectorAll(".jo-db-badge");
  badges.forEach(el => {
    const initial = Number(el.dataset.state ?? -1);
    applyBadgeState(el, [-1,0,1,2].includes(initial) ? initial : -1);
    el.addEventListener("click", e => {
      e.preventDefault();
      cycleBadge(el);
    });
    el.tabIndex = 0;
    el.addEventListener("keydown", e => {
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        cycleBadge(el);
      }
    });
  });
}
initBadgeSelector();
