<?php namespace Barryvdh\Debugbar;

use Exception;
use DebugBar\DebugBar;
use DebugBar\DataCollector\ConfigCollector;
use DebugBar\DataCollector\PhpInfoCollector;
use DebugBar\DataCollector\MessagesCollector;
use DebugBar\DataCollector\TimeDataCollector;
use DebugBar\DataCollector\MemoryCollector;
use DebugBar\DataCollector\ExceptionsCollector;
use DebugBar\DataCollector\RequestDataCollector;
use DebugBar\Bridge\SwiftMailer\SwiftLogCollector;
use DebugBar\Bridge\SwiftMailer\SwiftMailCollector;
use DebugBar\Bridge\MonologCollector;
use Barryvdh\Debugbar\DataCollector\LaravelCollector;
use Barryvdh\Debugbar\DataCollector\ViewCollector;
use Barryvdh\Debugbar\DataCollector\SymfonyRequestCollector;
use Barryvdh\Debugbar\DataCollector\FilesCollector;
use Barryvdh\Debugbar\DataCollector\LogsCollector;
use Barryvdh\Debugbar\DataCollector\AuthCollector;
use Barryvdh\Debugbar\DataCollector\QueryCollector;
use Barryvdh\Debugbar\Storage\FilesystemStorage;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * Debug bar subclass which adds all without Request and with LaravelCollector.
 * Rest is added in Service Provider
 *
 * @method void emergency($message)
 * @method void alert($message)
 * @method void critical($message)
 * @method void error($message)
 * @method void warning($message)
 * @method void notice($message)
 * @method void info($message)
 * @method void debug($message)
 * @method void log($message)
 */
class LaravelDebugbar extends DebugBar
{
    /**
     * The Laravel application instance.
     *
     * @var \Illuminate\Foundation\Application
     */
    protected $app;

    /**
     * True when booted.
     *
     * @var bool
     */
    protected $booted = false;

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    public function __construct($app=null){
        if(!$app){
            $app = app();   //Fallback when $app is not given
        }
        $this->app = $app;
    }

    /**
     * Check if the Debugbar is enabled
     * @return boolean
     */
    public function isEnabled(){
        return $this->app['config']->get('laravel-debugbar::config.enabled');
    }

    /**
     * Enable the Debugbar and boot, if not already booted.
     */
    public function enable(){
        $this->app['config']->set('laravel-debugbar::config.enabled', true);
        if(!$this->booted){
            $this->boot();
        }
    }

    /**
     * Disable the Debugbar
     */
    public function disable(){
        $this->app['config']->set('laravel-debugbar::config.enabled', false);
    }


    /**
     * Boot the debugbar (add collectors, renderer and listener)
     */
    public function boot(){
        if($this->booted){
            return;
        }

        $debugbar = $this;
        $app = $this->app;

        if($this->app['config']->get('laravel-debugbar::config.storage.enabled')){
            $path = $this->app['config']->get('laravel-debugbar::config.storage.path');
            $storage = new FilesystemStorage($this->app['files'], $path);
            $debugbar->setStorage($storage);
        }

        if($this->shouldCollect('phpinfo', true)){
            $this->addCollector(new PhpInfoCollector());
        }
        if($this->shouldCollect('messages', true)){
            $this->addCollector(new MessagesCollector());
        }
        if($this->shouldCollect('time', true)){

            $this->addCollector(new TimeDataCollector());

            $this->app->booted(function() use($debugbar)
            {
                if(defined('LARAVEL_START')){
                    $debugbar['time']->addMeasure('Booting', LARAVEL_START, microtime(true));
                }
            });

            //Check if App::before is already called..
            if(version_compare($app::VERSION, '4.1', '>=') && $this->app->isBooted()){
                $debugbar->startMeasure('application', 'Application');
            }else{
                $this->app->before(function() use($debugbar)
                {
                    $debugbar->startMeasure('application', 'Application');
                });
            }

            $this->app->after(function() use($debugbar)
            {
                $debugbar->stopMeasure('application');
                $debugbar->startMeasure('after', 'After application');
            });

        }
        if($this->shouldCollect('memory', true)){
            $this->addCollector(new MemoryCollector());
        }
        if($this->shouldCollect('exceptions', true)){
            try{
                $exceptionCollector = new ExceptionsCollector();
                if(method_exists($exceptionCollector, 'setChainExceptions')){
                    $exceptionCollector->setChainExceptions($this->app['config']->get('laravel-debugbar::config.options.exceptions.chain', true));
                }
                $this->addCollector($exceptionCollector);
                $this->app->error(function(Exception $exception) use($exceptionCollector){
                    $exceptionCollector->addException($exception);
                });
            }catch(\Exception $e){}
        }
        if($this->shouldCollect('laravel', false)){
            $this->addCollector(new LaravelCollector());
        }
        if($this->shouldCollect('default_request', false)){
            $this->addCollector(new RequestDataCollector());
        }

        if($this->shouldCollect('events', false) and isset($this->app['events'])){
            try{
                $this->addCollector(new MessagesCollector('events'));
                $dispatcher = $this->app['events'];
                $dispatcher->listen('*', function() use($debugbar, $dispatcher){
                    if(method_exists($dispatcher, 'firing')){
                        $event = $dispatcher->firing();
                    }else{
                        $args = func_get_args();
                        $event = end($args);
                    }
                    $debugbar['events']->info("Received event: ". $event);
                });
            }catch(\Exception $e){
                $this->addException(new Exception('Cannot add EventCollector to Laravel Debugbar: '. $e->getMessage(), $e->getCode(), $e));
            }
        }

        if($this->shouldCollect('views', true) and isset($this->app['events'])){
            try{
                $collectData = $this->app['config']->get('laravel-debugbar::config.options.views.data', true);
                $this->addCollector(new ViewCollector($collectData));
                $this->app['events']->listen('composing:*', function($view) use($debugbar){
                    $debugbar['views']->addView($view);
                });
            }catch(\Exception $e){
                $this->addException(new Exception('Cannot add ViewCollector to Laravel Debugbar: '. $e->getMessage(), $e->getCode(), $e));
            }
        }

        if($this->shouldCollect('route')){
            try{
                if(version_compare($app::VERSION, '4.1', '>=')){
                    $this->addCollector($this->app->make('Barryvdh\Debugbar\DataCollector\IlluminateRouteCollector'));
                }else{
                    $this->addCollector($this->app->make('Barryvdh\Debugbar\DataCollector\SymfonyRouteCollector'));
                }
            }catch(\Exception $e){
                $this->addException(new Exception('Cannot add RouteCollector to Laravel Debugbar: '. $e->getMessage(), $e->getCode(), $e));
            }
        }

        if( $this->shouldCollect('log', true) ){
            try{
                if($this->hasCollector('messages') ){
                    $logger = new MessagesCollector('log');
                    $this['messages']->aggregate($logger);
                    $this->app['log']->listen(function($level, $message, $context) use($logger)
                    {
                        try{
                            $logMessage = (string) $message;
                            if(mb_check_encoding($logMessage, 'UTF-8')){
                                $logMessage .= (!empty($context) ? ' '.json_encode($context) : '');
                            }else{
                                $logMessage = "[INVALID UTF-8 DATA]";
                            }
                        }catch(\Exception $e){
                            $logMessage = "[Exception: ".$e->getMessage() ."]";
                        }
                        $logger->addMessage('['.date('H:i:s').'] '. "LOG.$level: ". $logMessage, $level, false);
                    });
                }else{
                    $this->addCollector(new MonologCollector( $this->app['log']->getMonolog() ));
                }
            }catch(\Exception $e){
                $this->addException(new Exception('Cannot add LogsCollector to Laravel Debugbar: '. $e->getMessage(), $e->getCode(), $e));
            }
        }

        if($this->shouldCollect('db', true) and isset($this->app['db'])){
            $db = $this->app['db'];
            if( $debugbar->hasCollector('time') && $this->app['config']->get('laravel-debugbar::config.options.db.timeline', false)){
                $timeCollector = $debugbar->getCollector('time');
            }else{
                $timeCollector = null;
            }
            $queryCollector = new QueryCollector($timeCollector);

            if($this->app['config']->get('laravel-debugbar::config.options.db.with_params')){
                $queryCollector->setRenderSqlWithParams(true);
            }

            $this->addCollector($queryCollector);

            try{
                $db->listen(function($query, $bindings, $time, $connectionName) use ($db, $queryCollector)
                {
                    $connection = $db->connection($connectionName);
                    if( !method_exists($connection, 'logging') || $connection->logging() ){
                        $queryCollector->addQuery((string) $query, $bindings, $time, $connection);
                    }
                });
            }catch(\Exception $e){
                $this->addException(new Exception('Cannot add listen to Queries for Laravel Debugbar: '. $e->getMessage(), $e->getCode(), $e));
            }
        }

        if($this->shouldCollect('mail', true)){
            try{
                $mailer = $this->app['mailer']->getSwiftMailer();
                $this->addCollector(new SwiftMailCollector($mailer));
                if($this->app['config']->get('laravel-debugbar::config.options.mail.full_log') and $this->hasCollector('messages')){
                    $this['messages']->aggregate(new SwiftLogCollector($mailer));
                }
            }catch(\Exception $e){
                $this->addException(new Exception('Cannot add MailCollector to Laravel Debugbar: '. $e->getMessage(), $e->getCode(), $e));
            }
        }

        if($this->shouldCollect('logs', false)){
            try{
                $file = $this->app['config']->get('laravel-debugbar::config.options.logs.file');
                $this->addCollector(new LogsCollector($file));
            }catch(\Exception $e){
                $this->addException(new Exception('Cannot add LogsCollector to Laravel Debugbar: '. $e->getMessage(), $e->getCode(), $e));
            }
        }
        if($this->shouldCollect('files', false)){
            $this->addCollector(new FilesCollector());
        }

        if ($this->shouldCollect('auth', false)) {
            try{
                $authCollector = new AuthCollector($app['auth']);
                $authCollector->setShowName($this->app['config']->get('laravel-debugbar::config.options.auth.show_name'));
                $this->addCollector($authCollector);
            }catch(\Exception $e){
                $this->addException(new Exception('Cannot add AuthCollector to Laravel Debugbar: '. $e->getMessage(), $e->getCode(), $e));
            }
        }

        $renderer = $this->getJavascriptRenderer();
        $renderer->setBaseUrl($this->app['url']->asset('packages/maximebf/php-debugbar'));
        $renderer->setIncludeVendors($this->app['config']->get('laravel-debugbar::config.include_vendors', true));

        $this->booted = true;

    }

    /**
     * Check if this is a request to the Debugbar OpenHandler
     * 
     * @return bool
     */
    protected function isDebugbarRequest(){
        return $this->app['request']->segment(1) == '_debugbar';
    }
    
    /**
     * Modify the response and inject the debugbar (or data in headers)
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function modifyResponse($request, $response){
        $app = $this->app;
        if( $app->runningInConsole() or !$this->isEnabled() || $this->isDebugbarRequest()){
            return $response;
        }

        if($this->shouldCollect('config', false)){
            try{
                $configCollector = new ConfigCollector;
                $configCollector->setData($app['config']->getItems());
                $this->addCollector($configCollector);
            }catch(\Exception $e){
                $this->addException(new Exception('Cannot add ConfigCollector to Laravel Debugbar: '. $e->getMessage(), $e->getCode(), $e));
            }
        }

        /** @var \Illuminate\Session\SessionManager $sessionManager */
        $sessionManager = $app['session'];
        $httpDriver = new SymfonyHttpDriver($sessionManager, $response);
        $this->setHttpDriver($httpDriver);

        if($this->shouldCollect('symfony_request', true) and !$this->hasCollector('request')){
            try{
                $this->addCollector(new SymfonyRequestCollector($request, $response, $sessionManager));
            }catch(\Exception $e){
                $this->addException(new Exception('Cannot add SymfonyRequestCollector to Laravel Debugbar: '. $e->getMessage(), $e->getCode(), $e));
            }
        }

        if($response->isRedirection()){
            $this->stackData();
        }elseif( $request->isXmlHttpRequest() and $app['config']->get('laravel-debugbar::config.capture_ajax', true)){
            $this->sendDataInHeaders(true);
        }elseif(
            ($response->headers->has('Content-Type') && false === strpos($response->headers->get('Content-Type'), 'html'))
            || 'html' !== $request->format()
        ){
            //Do nothing
        }elseif($app['config']->get('laravel-debugbar::config.inject', true)){
            $this->injectDebugbar($response);
        }
        return $response;
    }

    public function shouldCollect($name, $default=false){
        return $this->app['config']->get('laravel-debugbar::config.collectors.'.$name, $default);
    }


    /**
     * Starts a measure
     *
     * @param string $name Internal name, used to stop the measure
     * @param string $label Public name
     */
    public function startMeasure($name, $label=null){
        if($this->hasCollector('time')){
            /** @var \DebugBar\DataCollector\TimeDataCollector $collector */
            $collector = $this->getCollector('time');
            $collector->startMeasure($name, $label);
        }
    }

    /**
     * Stops a measure
     *
     * @param string $name
     */
    public function stopMeasure($name)
    {
        if($this->hasCollector('time')){
            /** @var \DebugBar\DataCollector\TimeDataCollector $collector */
            $collector = $this->getCollector('time');
            try{
                $collector->stopMeasure($name);
            }catch(\Exception $e){
                //  $this->addException($e);
            }

        }
    }

    /**
     * Adds a measure
     *
     * @param string $label
     * @param float $start
     * @param float $end
     */
    public function addMeasure($label, $start, $end)
    {
        if($this->hasCollector('time')){
            /** @var \DebugBar\DataCollector\TimeDataCollector $collector */
            $collector = $this->getCollector('time');
            $collector->addMeasure($label, $start, $end);
        }
    }

    /**
     * Utility function to measure the execution of a Closure
     *
     * @param string $label
     * @param \Closure|callable $closure
     */
    public function measure($label, \Closure $closure)
    {
        if($this->hasCollector('time')){
            /** @var \DebugBar\DataCollector\TimeDataCollector $collector */
            $collector = $this->getCollector('time');
            $collector->measure($label, $closure);
        }
    }

    /**
     * Adds an exception to be profiled in the debug bar
     *
     * @param Exception $e
     */
    public function addException(Exception $e)
    {
        if($this->hasCollector('exceptions')){
            /** @var \DebugBar\DataCollector\ExceptionsCollector $collector */
            $collector = $this->getCollector('exceptions');
            $collector->addException($e);
        }
    }

    /**
     * Adds a message to the MessagesCollector
     *
     * A message can be anything from an object to a string
     *
     * @param mixed $message
     * @param string $label
     */
    public function addMessage($message, $label = 'info')
    {
        if($this->hasCollector('messages')){
            /** @var \DebugBar\DataCollector\MessagesCollector $collector */
            $collector = $this->getCollector('messages');
            $collector->addMessage($message, $label);
        }
    }

    /**
     * Injects the web debug toolbar into the given Response.
     *
     * @param \Symfony\Component\HttpFoundation\Response $response A Response instance
     * Based on https://github.com/symfony/WebProfilerBundle/blob/master/EventListener/WebDebugToolbarListener.php
     */
    public function injectDebugbar(Response $response)
    {
        $content = $response->getContent();

        $renderer = $this->getJavascriptRenderer();
        if($this->getStorage()){
            $openHandlerUrl = $this->app['url']->route('debugbar.openhandler');
            $renderer->setOpenHandlerUrl($openHandlerUrl);
        }

        if(method_exists($renderer, 'addAssets')){
            $dir = 'packages/barryvdh/laravel-debugbar';
            $renderer->addAssets(array('laravel-debugbar.css'), array(), $this->app['path.public'].'/'.$dir, $this->app['url']->asset($dir));
        }

        $renderedContent = $renderer->renderHead() . $renderer->render();

        $pos = mb_strripos($content, '</body>');
        if (false !== $pos) {
            $content = mb_substr($content, 0, $pos) . $renderedContent . mb_substr($content, $pos);
        }else{
            $content = $content . $renderedContent;
        }

        $response->setContent($content);
        
        // Stop further rendering (on subrequests etc)
        $this->disable();
    }

    /**
     * Collect data in a CLI request
     *
     * @return array
     */
    public function collectConsole(){
        if(!$this->isEnabled()){
            return;
        }

        $this->data = array(
            '__meta' => array(
                'id' => $this->getCurrentRequestId(),
                'datetime' => date('Y-m-d H:i:s'),
                'utime' => microtime(true),
                'method' => 'CLI',
                'uri' => isset($_SERVER['argv']) ? implode(' ',$_SERVER['argv']) : null,
                'ip' => isset($_SERVER['SSH_CLIENT']) ? $_SERVER['SSH_CLIENT'] : null
            )
        );

        foreach ($this->collectors as $name => $collector) {
            $this->data[$name] = $collector->collect();
        }

        if ($this->storage !== null) {
            $this->storage->save($this->getCurrentRequestId(), $this->data);
        }

        return $this->data;
    }

    /**
     * Magic calls for adding messages
     *
     * @param string $method
     * @param array $args
     * @return mixed|void
     */
    public function __call($method, $args)
    {
        $messageLevels = array('emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log');
        if(in_array($method, $messageLevels)){
            $this->addMessage($args[0], $method);
        }
    }

}
