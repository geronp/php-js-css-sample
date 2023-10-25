import Router from "./util/router";

import * as bootstrap from "bootstrap";

import common from "./routes/common";
import contact from "./routes/contact";
import home from "./routes/home";
import insights from "./routes/insights";
import leadership from "./routes/leadership";
import newsPress from "./routes/newsPress";
import search from "./routes/search";
import events from "./routes/events";
import lightboxLabs from "./routes/lightboxLabs";
import singleProduct from "./routes/singleProduct";
import taxByindustry from "./routes/taxByindustry";

const routes = new Router({
	bootstrap,
	common,
	contact,
	home,
	insights,
	leadership,
	newsPress,
	search,
	events,
	lightboxLabs,
	singleProduct,
	taxByindustry
});

document.addEventListener("DOMContentLoaded", function () {
	routes.loadEvents();
}, false);