<?php

	namespace net\pp3345\ppFramework\Middleware;

	use net\pp3345\ppFramework\Middleware;

	Middleware::register("RemoveFirstArgument", function(...$args) {
		array_shift($args);
		return $args;
	});
