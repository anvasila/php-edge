<?php
namespace Edge\Core;

use Edge\Core\Database\MysqlMaster,
    Edge\Core\Exceptions\NotFound;

class Router{
	protected $controller;
	protected $method;
	protected $args = array();
    protected $response;
    protected $request;
    protected $routes;
    protected $permissions = null;
    protected $shutdownCallbacks = [];

	public function __construct(array $routes){
        $this->routes = $routes;
        $this->response = Edge::app()->response;
        $this->request = Edge::app()->request;
        register_shutdown_function(array($this, 'onApplicationShutdown'));
        Edge::app()->router = $this;
		try{
			$this->setAttrs();
		}catch(Exception $e){
            $msg = $e->getMessage();
            Edge::app()->logger->err($msg);
			$this->handleServerError($msg);
			$this->response->write();
		}
	}

	public function &getArgs(){
		return $this->args;
	}

    public function getPermissions(){
        return $this->permissions;
    }

	public function getAction(){
		return $this->method;
	}

    public function getController(){
        return $this->controller;
    }

    public function onApplicationShutdown(){
        $error = error_get_last();
        $fatal = ($error && in_array($error['type'], [E_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR]));
        if ($fatal){
            $this->handleServerError($error["message"]);
            Edge::app()->response->write();
        }
    }

    /**
     * Return the URL from the routes array that corresponds
     * to the selected attributes
     * ie
     * parent::createLink(get_called_class(), 'updateRole', [':id' => $role->id], 'POST');
     * or
     * parent::createLink('Application\Controllers\Home', 'index', ["anchor"=>"#list_products"])
     * @param $controller
     * @param $action
     * @param array $attrs
     * @param string $method
     * @return string
     */
    public function createLink($controller, $action, array $attrs=array(), $method='GET'){
        $routes = $this->routes;
        if(!isset($routes[$method])){
            return null;
        }
        //merge this method routes with the * (common) routes if they exist
        $routes[$method] = array_merge($routes[$method], isset($routes["*"]) ? $routes["*"] : array() );
        foreach($routes[$method] as $url=>$options){
            if($options[0] == $controller && $options[1] == $action){
                $anchor = '';
                if(isset($attrs['anchor'])){
                    $anchor = $attrs['anchor'];
                    unset($attrs['anchor']);
                }
                if(substr($url, strlen($url) - 1) == "*"){
                    //handle case such as /css/:file/*
                    if(strstr($url, ":") !== false){
                        $url = substr($url, 0, strlen($url) - 2);
                    }
                    else{
                        //handle case such as /cms/page/create/*
                        $url = substr($url, 0, strlen($url) - 1);
                        return $url . join("/", array_values($attrs));
                    }
                }
                $keys = array_keys($attrs);
                $vals = array_values($attrs);
                return str_replace($keys, $vals, $url).$anchor;
            }
        }
        return null;
    }

    protected function handleServerError($msg){
        $edge = Edge::app();
        $class = $edge->getConfig('serverError');
        try {
            $this->controller = new $class[0];
            $this->method = $class[1];
            if($this->response->httpCode == 200){
                $this->response->httpCode = 500;
            }
            $this->response->body = call_user_func(array($this->controller, $this->method), $msg);
        }catch(\Exception $e){
            $this->response->httpCode = 500;
            $this->response->body = $e->getMessage();
        }
    }

	protected function handle404Error($message = "" ){
        $edge = Edge::app();
        $class = $edge->getConfig('notFound');
        $this->controller = new $class[0];
        $this->method = $class[1];
        $this->response->httpCode = 404;
        $this->response->body = call_user_func(array($this->controller, $this->method), $this->request->getRequestUrl(), $message);
	}

    /**
     * Try to map a URL to a Controller=>action array
     * Routes are defined as
     *
     * 'GET' => array(
            '/' => array("Home", "index"),
            '/page/action/:name/:id' => array("Home", "index"),
            '/user/view/1' => array("User", "display")
            '/user/edit/:id' => array("User", "edit"),
            '/user/load/*' => array("User", "load"),
            '/user/display/:id/*' => array("User", "show"),
            '/user/:id/page/:number => array("Application\Controllers\User", "display"),
        'POST' => array(
            '/rest/api/:id' => array('Home', 'post')
        ),
        '*' => array(
            '/api/update/:id' => array("Home", "test")
        )
     *
     * Try to find an exact match of the url
     * within the array's keys. This is the most quick and efficient way
     * to resolve routes so try to abide to this approach as much as possible.
     *
     *
     * @param $url
     * @param $routes
     * @return array|bool
     */
    private function uriResolver($url, $routes){
        if(isset($routes[$url])){
            $ret = $routes[$url];
            $ret[] = array();
            return $ret;
        }
        if(substr_count($url, "?") > 0){
            $url = explode("?", $url)[0];
        }
        foreach($routes as $requestedUrl => $attrs){
            $greedy = "";
            //if route has been marked as greedy, by adding a
            ///* on the end of the route, remove it and set the greedy pattern
            if(substr($requestedUrl, strlen($requestedUrl)-1) == "*"){
                $requestedUrl = substr($requestedUrl, 0, strlen($requestedUrl)-2);
                $greedy = "(.*)";
            }
            //replace any :tokens in the route with a regex
            $pattern = "@^" . preg_replace('/\\\:[a-zA-Z0-9\_\-]+/', '([a-zA-Z0-9\-\_\.%\(\)]+)', preg_quote($requestedUrl), -1, $count) . "$greedy$@D";

            $matches = [];
            if(preg_match($pattern, $url, $matches)){
                array_shift($matches);
                $matches = array_filter($matches);
                if($greedy && count($matches) > $count){
                    $extraArgs = explode("/", array_pop($matches));
                    array_shift($extraArgs);
                    $matches = array_merge($matches, $extraArgs);
                }
                $attrs[] = array_map('htmlspecialchars', $matches);
                return $attrs;
            }
        }
        return false;
    }

    /**
     * Map URI to Controller and Action based on the
     * http method
     * @param $uri
     * @return array|bool
     */
    protected function resolveRoute($uri){
        $httpMethod = Edge::app()->request->getHttpMethod();
        $route = false;
        if(array_key_exists($httpMethod, $this->routes)){
            $routes = $this->routes[$httpMethod];
            $route = $this->uriResolver($uri, $routes);
        }
        if(!$route && isset($this->routes['*'])){
            $route = $this->uriResolver($uri, $this->routes['*']);
        }
        return $route;
    }

	protected function setAttrs(){
		$url = $_SERVER['REQUEST_URI'];
		if(empty($url)){
			$url = "//";
            $_SERVER['REQUEST_URI'] = "/";
		}
		if ($url != '/' && $url[strlen($url)-1] == '/'){
			$url = substr($url, 0, -1);
		}
        $route = $this->resolveRoute($url);
        if(!$route){
            Edge::app()->logger->err("$url is not mapped to any route");
            $this->handle404Error();
            $this->response->write();
        }

		$this->controller = ucfirst($route[0]);
        $this->method = $route[1];
        $this->args = array_values($route[2]);
        if(isset($route['acl'])){
            $this->permissions = $route['acl'];
            unset($route['acl']);
        }

        if(!$this->request->is('get')){
            $extraArgs = $this->request->getParams();
            if($extraArgs){
                $this->args[] = $extraArgs;
            }
            if($this->request->isJsonRpc()){
                $this->method = $this->request->getTransformer()->method;
            }
        }
	}

    protected static function getFilters(\Edge\Controllers\BaseController $instance){
        $filters = $instance->filters();
        if(count($filters) > 0){
            $filterInstances = array();
            foreach($filters as $filter){
                $class = array_shift($filter);
                if(count($filter) > 0){
                    $instance = new $class($filter);
                }
                else{
                    $instance = new $class;
                }
                $filterInstances[] = $instance;
            }
            return $filterInstances;
        }
        return $filters;
    }

    /**
     * Execute the filters
     * Iterate each one and invoke the filter
     * If any of the filters returns false, we stop
     * the execution and return.
     * @param array $filters
     * @param $method (preProcess | postProcess)
     */
    private function runFilters(array $filters, $method){
        foreach($filters as $filter){
            if($filter->appliesTo($this->method)){
                $val = $filter->{$method}($this->response, $this->request);
                if($val === false){
                    return false;
                }
            }
        }
        return true;
    }

    public function invoke(){
        $class = $this->controller;
        $this->controller = new $class();
        if(method_exists($this->controller, $this->method) || method_exists($this->controller, '__call')){
            try{
                $filters = static::getFilters($this->controller);
                $invokeRequest = $this->runFilters($filters, 'preProcess');
                if($invokeRequest){
                    $processed = false;
                    $retries = 0;
                    $max_retries = 20;

                    while(!$processed && ($retries < $max_retries)) {
                        try{
                            $retries++;
                            $this->response->body = $this->request
                                                          ->getTransformer()
                                                          ->encode(call_user_func_array(array($this->controller,
                                                                                                $this->method),
                                                                                        $this->args));
                            $processed = true;
                        }catch(Exceptions\DeadLockException $e) {
                            Edge::app()->logger->info('RETRYING TRANSACTION');
                            usleep(100);
                        }
                    }
                    if(!$processed) {
                        Edge::app()->logger->err('DEADLOCK ERROR');
                        throw new \Exception('Deadlock detected');
                    }
                }
                $this->runFilters($filters, 'postProcess');
                $this->executeShutDownCallbacks();
            }
            catch(\Exception $e){
                $db = Edge::app()->db;
                if($db instanceof MysqlMaster){
                    $db->rollback();
                }
                Edge::app()->logger->err($e->getMessage()."\n".$e->getTraceAsString());
                if($e instanceof NotFound){
                    $this->handle404Error($e->getMessage());
                }
                else{
                    $this->handleServerError($e->getMessage());
                }
            }
        }
        else{
            $this->handle404Error();
        }
        $this->response->write();
    }

    /**
     * Run any callbacks before sending the response
     */
    public function executeShutDownCallbacks(){
        if($this->shutdownCallbacks){
            foreach($this->shutdownCallbacks as $fn){
                $fn();
            }
        }
    }

    /**
     * Add a shutdown callback to the list
     * This callback will be run after the controller method and any
     * filters have run
     * @param callable $fn
     */
    public function addCallBack(callable $fn){
        $this->shutdownCallbacks[] = $fn;
    }
}