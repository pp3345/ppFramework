<?php

	namespace net\pp3345\ppFramework\Middleware;

	use net\pp3345\ppFramework\Middleware;

	Middleware::register("RemoveLastArgument", function(...$args) {
		array_pop($args);
		return $args;
	});
