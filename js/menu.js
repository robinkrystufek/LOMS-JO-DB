let navbar = document.querySelector(".navbar");
let searchBox = document.querySelector(".login-box .bx-user");
searchBox.addEventListener("click", ()=>{
  navbar.classList.toggle("showInput");
  if(navbar.classList.contains("showInput")){
    searchBox.classList.replace("bx-user" ,"bx-x");
  }else {
    searchBox.classList.replace("bx-x" ,"bx-user");
  }
});
let navLinks = document.querySelector(".nav-links");
let menuOpenBtn = document.querySelector(".navbar .bx-menu");
let menuCloseBtn = document.querySelector(".nav-links .bx-x");
menuOpenBtn.onclick = function() {
  navLinks.style.left = "0";
}
menuCloseBtn.onclick = function() {
  navLinks.style.left = "-100%";
}
let jsArrow = document.querySelector(".js-arrow");
jsArrow.onclick = function() {
  navLinks.classList.toggle("show3");
}

