<?php

declare(strict_types=1);

namespace frdlweb\Api\Rpc\Server;


use frdlweb\Api\Rpc\DiscoverMethod;
use frdlweb\Api\Rpc\MethodDiscoverableInterface;
use frdlweb\Api\Rpc\MetadataException;
use frdlweb\Api\Rpc\SchemaLoader;

use LogicException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;



use TypeError;
use UMA\JsonRpc\Error;
use UMA\JsonRpc\Request;
use UMA\JsonRpc\Response;
use UMA\JsonRpc\Internal\Assert;
use UMA\JsonRpc\Internal\Input;
use UMA\JsonRpc\Internal\MiddlewareStack;
use UMA\JsonRpc\Internal\Validator;
use UMA\JsonRpc\Procedure;
use Opis\JsonSchema\Validator as OpisValidator;


use Webfan\Homepagesystem\EventFlow\State as EventEmitter;
use webfan\hps\Event as Event;




class EventableServer extends EventEmitter 
{

	protected $config = [];
	
    /**
     * @var ContainerInterface
   */
    protected $container;
  
    /**
     * @var string[]
   */
    protected $methods;
  
    /**
     * @var string[]
    */
    protected $middlewares;
 
    /**
     * @var int|null
    */
    protected $batchLimit;
	 
    public function __construct(ContainerInterface $container, int $batchLimit = null, array $config = null, bool $discovery = true)
    {
		if(!is_array($config)){
		  $config = [];	
		}
		$this->config = array_merge([
		'schemaLoaderPrefix' => '',
		'schemaLoaderDirs' => [],	
	//	'schemaCacheDir' => __DIR__.\DIRECTORY_SEPARATOR.'schema-store'.\DIRECTORY_SEPARATOR,			
		'schemaCacheDir' => sys_get_temp_dir() . \DIRECTORY_SEPARATOR . get_current_user(). \DIRECTORY_SEPARATOR . 'json-schema-store' . \DIRECTORY_SEPARATOR,
		'discovery' => 	$discovery,
		'meta' => [
		  'openrpc' => '1.0.0-rc1',
		  "info" => [
              "title" => "JSON-RPC Server",
              "description" =>"This the RPC-part of an Frdlweb API Server definition https://look-up.webfan3.de/?goto=oid%3A1.3.6.1.4.1.37553.8.1.8.1.13878",
              "version" => "1.0.0",
          ],
		  'servers' => [
			[
		     'name' => 'Webfan Homepagesystem RPC API',
		     'summary' => 'Webfan Homepagesystem RPC API methods description',
		     'description' => 'This is the RPC part of an implementation of the Frdlweb API Specification (1.3.6.1.4.1.37553.8.1.8.1.13878)',
		   //  'url' => 'https://'.$_SERVER['SERVER_NAME'].'/software-center/modules-api/rpc/0.0.2/',
		 	  'url' => 'https://'.$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'],
		    ]
			  
		  ],
		    'methods' => [],
		    'components' => [
			     'links' => [],
			     'contentDescriptors' => [],
			     'schemas' => [],
			     'examples' => [],
			  
			  ],
		 ],	
		], $config);
		
		/*
        parent::__construct($container, $batchLimit);
		*/
        $this->container = $container;
        $this->batchLimit = $batchLimit;
        $this->methods = [];
        $this->middlewares = [];		

		
		if(true === $this->config['discovery']){
			/* $this->setDiscovery(DiscoverMethod::class, [$this, 'discoveryFactory']); */
			
			    $callable = [$this, 'discoveryFactory'];
				$this->setDiscovery(DiscoverMethod::class,static function(ContainerInterface $c) use($callable){
					return call_user_func_array($callable, func_get_args());
				});
				
		}
		
		
		
    }	

   public function discoveryFactory(ContainerInterface $c) : MethodDiscoverableInterface{
          $DiscoverMethod = new DiscoverMethod($this);
          $DiscoverMethod->config(null, $this->config['meta']);
	   
	   
	   return $DiscoverMethod;
   }	


	
  public function setDiscovery($serviceId, callable $factory){
   if(!$this->getContainer()->has( $serviceId)){  
	 if(
		 $this->container instanceof \Di\CompiledContainer
		 || 'CompiledContainer' === basename(get_class($this->container))
	   
	   ) {
		 $this->getContainer()->set( $serviceId, call_user_func_array($factory, [$this->container]));		
	 }elseif($factory instanceof \closure || 'ContainerBuilder' === basename(get_class($this->container)) ){ 
	     $this->getContainer()->set( $serviceId, $factory);		  
	 }else{ 
	     //$this->getContainer()->set( $serviceId, $factory);		  
		  $this->getContainer()->set( $serviceId, call_user_func_array($factory, [$this->container]));	
	 }
	}
	  
 	 	
	  $this->set('rpc.discover', $serviceId);
/**/	  
	  return $this;
  }
	
  public function getMethodDefinitions(){
	 return $this->methods;  
  }
	
  public function getContainer():ContainerInterface{
	 return $this->container;  
  }
	
  public function getConfig(){
	 return $this->config;  
  }
	
	public static function event($name, Request $request = null, Response $response = null, EventableServer $server = null) : Event {
	    $event = new Event($name);
		if(null!==$request)$event->setArgument('request', $request);
		if(null!==$response)$event->setArgument('response', $response);
		if(null!==$server)$event->setArgument('server', $server);
		
	
		return $event;
	}
	
	
    public function set(string $method, string $serviceId): EventableServer
    {
        if (!$this->container->has($serviceId)) {
            throw new LogicException("Cannot find service '$serviceId' in the container");
        }

        $this->methods[$method] = $serviceId;

        return $this;
    }

    public function attach(string $serviceId): EventableServer
    {
        if (!$this->container->has($serviceId)) {
            throw new LogicException("Cannot find service '$serviceId' in the container");
        }

        $this->middlewares[$serviceId] = null;

        return $this;
    }

    /**
     * @throws TypeError
     */
    public function run(string $raw): ?string
    {
		

        $input = Input::fromString($raw, true);
	
        if (!$input->parsable()) {
            return self::end(Error::parsing(), null, null, $this);
        }
	
		$Event = self::event('run.before', null, null, $this);
		$Event->setArgument('input', $input);
		$this->emit($Event->getName(), $Event);
		

        if ($input->isArray()) {
            if ($this->tooManyBatchRequests($input)) {
                return self::end(Error::tooManyBatchRequests($this->batchLimit), null, null, $this);
            }			
            return $this->batch($input);
        }

        return $this->single($input);
    }

    protected function batch(Input $input): ?string
    {
        \assert($input->isArray());

			$Event = self::event('batch.before', null, null, $this);		
			$Event->setArgument('input', $input);		
			$this->emit($Event->getName(), $Event);		
		
        $responses = [];
        foreach ($input->data() as $request) {
            $pseudoInput = Input::fromSafeData($request);

            if (null !== $response = $this->single($pseudoInput)) {
                $responses[] = $response;
            }
        }
		
			$Event = self::event('batch.after', null, null, $this);		
			$Event->setArgument('input', $input);			
			$Event->setResult('responses', $responses);	
			$this->emit($Event->getName(), $Event);
		
        return empty($responses) ?
            null : \sprintf('[%s]', \implode(',', $responses));
    }

    /**
     * @throws TypeError
     */
    protected function single(Input $input): ?string
    {
        if (!$input->isRpcRequest()) {
            return self::end(Error::invalidRequest());
        }

        $request = new Request($input);

        if (!\array_key_exists($request->method(), $this->methods)) {
            return self::end(Error::unknownMethod($request->id()), $request, null, $this);
        }

        try {
            $procedure = Assert::isProcedure(
                $this->container->get($this->methods[$request->method()])
            );
        } catch (ContainerExceptionInterface | NotFoundExceptionInterface $e) {
            return self::end(Error::internal($request->id()), $request,  null, $this);
        }

		
		
        if (!Validator::validate($procedure->getSpec(), $request->params())) {
            return self::end(Error::invalidParams($request->id()), $request, $procedure, $this);
        }

        $stack = MiddlewareStack::compose(
            $procedure,
            ...\array_map(function(string $serviceId) {
                return $this->container->get($serviceId);
            }, \array_keys($this->middlewares))
        );
         
	
			$Event = self::event('method', $request, $stack($request), $this);			
			$Event->setArgument('procedure', $procedure);	
			$this->emit($Event->getName(), $Event);		
		
					
        return self::end($Event->getArgument('response'), $Event->getArgument('request'), $Event->getArgument('procedure'), $Event->getArgument('server'));
    }

    protected function tooManyBatchRequests(Input $input): bool
    {
        \assert($input->isArray());

        return \is_int($this->batchLimit) && $this->batchLimit < \count($input->data());
    }

    protected static function end(Response $response, Request $request = null, Procedure $procedure = null, EventableServer $Server = null): ?string
    {
		
	
		if($response instanceof Error){
			$Event = self::event('error', $request, $response, $Server);			
			$Event->setArgument('procedure', $procedure);
			$Event->setResult($response);
			$Server->emit($Event->getName(), $Event);	
		}
		 
		if( $procedure && true !== $response instanceof Error && $procedure instanceof MethodDiscoverableInterface){
			
		   $spec = 	$procedure->getResultSpec();
				
			$result = json_decode(json_encode($response));
	 
           if (!self::validateResponse($validation, $spec, $result->result, $Server)) {			 
			   $ea=$validation->getFirstError()->errorArgs();
              return self::end(new Error($request->id(), 'Invalid result '.print_r($ea,true),  $result->result), $request, $procedure, $Server); 
           }				
		}
	
		
			$Event = self::event('end', $request, $response, $Server);			
			$Event->setArgument('procedure', $procedure);
		    $Event->setArgument('response', $response);
		 //  $Event->setResult($response);	    
		      
		
			$Server->emit($Event->getName(), $Event);	
		
		$r = ($Event->getResult()) ? $Event->getResult() : $Event->getArgument('response');
			
        return $request instanceof Request && null === $request->id() 
							  ? null : \json_encode($r);
    }	
	
	
	
    public static function validateResponse(&$validation = null, \stdClass $schema, $data, EventableServer $Server = null): bool
    {
		
		
		$Event = self::event('validate.before', null, null, $Server);		
		$Event->setArgument('payload', $data);
    		$Server->emit($Event->getName(), $Event);	
		
	
		
        \assert(false !== \json_encode($Event->getArgument('payload')));

		
		
		if(null!==$Server){
		  $config = $Server->getConfig();	
		}else{
			$config = [
				        'schemaLoaderPrefix' => 'https://json-schema.org',
		                'schemaLoaderDirs' => [],	
	                 	'schemaCacheDir' =>sys_get_temp_dir() . \DIRECTORY_SEPARATOR . get_current_user(). \DIRECTORY_SEPARATOR . 'json-schema-store' . \DIRECTORY_SEPARATOR,	
				];
		}
	
        $validation = (new OpisValidator)
			->setLoader(new SchemaLoader($config['schemaLoaderPrefix'],
									 $config['schemaLoaderDirs'], 
									 $config['schemaCacheDir']))
		
            ->dataValidation($Event->getArgument('payload'), $schema);
		
		
		
		
		
			
	    $Event = self::event('validate.after', null, null, $Server);
	    $Event->setArgument('validation', $validation);
	    $Event->setResult($validation->isValid());
    	    $Server->emit($Event->getName(), $Event);			
		
		return $Event->getResult();
    }
	
}
