////////////////////////
// BLOG POSTS MODULE

let blogPosition = 0;
const blogSliderTime = 3500;
const blogMarginCard = 20;

const blogSliderList = document.querySelector("#blogSliderList");
const blogSliderControls = document.querySelector(".blogSlider__control");

const blogCards = document.querySelectorAll(".blogMobile__card");
blogCards.forEach((blogCard, x) => {
  var div = document.createElement("div");
  div.classList.add("blogSlider__button");
  if (x == 0) div.classList.add("blogBtnSliderSelected");
  div.dataset.slide = x;
  blogSliderControls.appendChild(div);
});

const blogButtons = document.querySelectorAll(".blogSlider__button");
blogButtons.forEach((btn) => {
  btn.addEventListener("click", (e) => blogHandleClick({ e }));
});

function blogHandleClick({ e }) {
  cleanBlogButtons();

  let id = e.target.dataset.slide;
  blogButtons[id].classList.add("blogBtnSliderSelected");

  blogPosition = id;

  let margin = 0;
  for (var i = 0; i < id; i++) {
    margin += blogCards[i].offsetWidth + blogMarginCard;
  }
  blogSliderList.style.marginLeft = "-" + margin + "px";
}

function cleanBlogButtons() {
  blogButtons.forEach((btn) => {
    btn.classList.remove("blogBtnSliderSelected");
  });
}

window.addEventListener("load", () => {
  setInterval(() => {
    const isMobile = window.matchMedia("(max-width: 768px)").matches;
    if (isMobile) {
      blogPosition >= blogCards.length - 1
        ? (blogPosition = 0)
        : blogPosition++;
    } else {
      blogSliderList.style.marginLeft = "0";
    }
    blogButtons[blogPosition].click();
  }, blogSliderTime);
});

var blogPosts = document.querySelectorAll(".blogPosts__card");
attachBlogClick(blogPosts);

var blogPostsMob = document.querySelectorAll(".blogMobile__card");
attachBlogClick(blogPostsMob);

function attachBlogClick(posts) {
    posts.forEach((post) => {
        post.addEventListener("click", () => {
            var blogLinkTag = post.querySelector(".blogLink");
            if(blogLinkTag) {
                window.location.href = blogLinkTag.href;
            }
        });
    });
    post.addEventListener("keypress", (event) => {
        if (event.key === "Enter") {
            event.preventDefault();
            var blogLinkTag = post.querySelector(".blogLink");
            if(blogLinkTag) {
                window.location.href = blogLinkTag.href;
            }
        }
        
    });
}
