<?php

	namespace net\pp3345\ppFramework\Hook\View;

	use net\pp3345\ppFramework\View;

	interface BeforeRenderHook {
		public function beforeRenderView(View $view, $template);
	}
