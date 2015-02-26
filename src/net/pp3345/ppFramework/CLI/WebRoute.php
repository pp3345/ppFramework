<?php

    namespace net\pp3345\ppFramework\CLI;

    use net\pp3345\ppFramework;
    use net\pp3345\ppFramework\Singleton;

    class WebRoute {
	    use Singleton;

	    public function WebRoute(ppFramework $application, $path = "/", $method = "GET", $cookies = "") {
		    foreach(explode(";", $cookies) as $cookie) {
			    $cookie = explode("=", $cookie, 2);
			    $_COOKIE[$cookie[0]] = isset($cookie[1]) ? $cookie[1] : "";
		    }

		    try {
			    $application->route($method, $path);
		    } catch(ppFramework\Exception\NotFoundException $e) {
			    $this->help();
			    throw $e;
		    }
	    }

	    public function help() {
		    echo 'Usage: WebRoute <path = "/"> <method = "GET"> <cookies>' . PHP_EOL;
	    }
    }
