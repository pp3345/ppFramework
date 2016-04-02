<?php

	namespace net\pp3345\ppFramework\Hook\View;

	use net\pp3345\ppFramework\View;

	interface AfterRenderHook {
		public function afterRenderView(View $view, $template, $output);
	}
