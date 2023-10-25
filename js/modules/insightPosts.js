import Loading from "../util/loader";
import Pager from "../util/pagination";
import Posts from "../util/posts";

export default class InsightPosts {
	constructor() {
		this.init();
	}

	init() {
		this.loading = new Loading();

		this.pager = new Pager();

		this.posts = new Posts(this);

		this.ui = {
			wrapper: document.getElementById("post-wrapper"),
			checkboxes: document.querySelectorAll("input[type=checkbox]"),
			view: document.getElementById("view"),
			pager: document.getElementById("pager"),
			pagerMob: document.getElementById("pagerMob"),
			search: document.getElementById("search"),
			searchButton: document.getElementById("searchButton"),
			sortBy: document.querySelectorAll(".sortBy"),
			resultsCount: document.querySelectorAll(".resultsCount"),
			filterTags: document.getElementById("filterTags"),
		};

		this.data = {
			contentType: [], //blog, report
			topics: [],
			industries: [],
			filterTags: [],
			page: 1,
			take: 6,
			orderBy: "",
			search: "",
		};

		this.ui.view.value = this.data.take;

		let params = new URLSearchParams(document.location.search);
		let id = params.get("term");
		if (id != null) {
			document.getElementById(id).checked = true;
			this.data.topics.push(id);
		}

		console.log(this.data);

		this.posts.fetchPosts();

		this._addEventListeners();
	}

	_addEventListeners() {
		let self = this;

		this.ui.checkboxes.forEach((cb) => {
			cb.addEventListener("change", () => {
				const typesChecked = [...self.ui.checkboxes].filter(item => item.checked && item.value == "types");
				const topicsChecked = [...self.ui.checkboxes].filter(item => item.checked && item.value == "topics");
				const industriesChecked = [...self.ui.checkboxes].filter(item => item.checked && item.value == "industries");

				self.data.contentType = Array.from(typesChecked).map(cb => cb.id);
				self.data.topics = Array.from(topicsChecked).map(cb => cb.id);
				self.data.industries = Array.from(industriesChecked).map(cb => cb.id);

				const allChecked = [...self.ui.checkboxes].filter(item => item.checked);
				self.data.filterTags = Array.from(allChecked).map(e => ({ name: e.name, id: e.id }));
				self.data.filterTags.sort((a, b) => (a.name > b.name) ? 1 : 0);
				self.data.page = 1;

				self.posts.buildFilterTags();

				self.posts.fetchPosts();
			});
		});

		this.posts.eventListeners();
	}

	_buildFormData() {
		var formData = new FormData();
		formData.append("action", "LoadInsightPosts");
		formData.append("take", this.data.take);
		formData.append("page", this.data.page);

		if (this.data.contentType.length == 1) {
			formData.append("contentType", this.data.contentType[0]);
		}

		if (this.data.topics.length > 0) {
			formData.append("topics", this.data.topics);
		}

		if (this.data.industries.length > 0) {
			formData.append("industries", this.data.industries);
		}

		if (this.data.search.length > 0) {
			formData.append("search", this.data.search);
		}

		if (this.data.orderBy.length > 0) {
			formData.append("orderBy", this.data.orderBy);
		}

		return formData;
	}

	_processPosts(posts, data) {
		let self = this;

		// Clear the previous results
		if (self.ui.wrapper.hasChildNodes()) {
			self.ui.wrapper.innerHTML = "";
		}

		let html = "";

		posts.forEach((post) => {
			let style = post.thumbnail ? "style='background-image:url(" + post.thumbnail + ");'" : "";

			html += `
			<div class='col-12 col-md-6'>
				<a href="${post.link_url}" target="_self" class="card" ${style}>
						<article class="card-body">
							<h5 class="card-title">${post.title}</h5>
							<div class="card-text">${post.brief}</div>
							<div class="card-meta">
								<div class="card-publishing-date">${post.date}</div>
								<div class="card-reading-time">${post.reading}</div>
							</div>
						</article>
						<div class="card-gradient"></div>
				</a>
			</div>`;
		});

		self.ui.wrapper.innerHTML = html;

		self.ui.resultsCount.forEach((result) => {
			result.innerHTML = `<span class="Results">Results: ${data.total} items</span>`;
		});
	}
}
