<?php

namespace qoraiche\mailEclipse;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\View;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use RegexIterator;
use ReflectionClass;
use ReflectionProperty;
use ErrorException;
use Validator;

class mailEclipse
{

	public static $view_namespace = 'maileclipse';

	protected static $templates_table = 'maileclipse_templates';


	static public function getMailables()
	{
		return self::mailablesList();
	}

	
	static public function getMailable($key, $name)
	{
		$filtered = collect(self::getMailables())->where($key, $name);
		return $filtered;
	}

	static public function deleteTemplate($templateSlug)
	{

		
		$template = DB::table(self::$templates_table)
		            ->where('template_slug', $templateSlug)->first();

		if ( !is_null($template) ){

			DB::table(self::$templates_table)->where('template_slug', '=', $templateSlug)->delete();

			$template_view = self::$view_namespace.'::templates.'.$templateSlug;
			$template_plaintext_view = $template_view.'_plain_text';

			// return $template_plaintext_view;

			if ( View::exists( $template_view ) )
			{
				unlink( View($template_view)->getPath() );

				if ( View::exists( $template_plaintext_view ) )
				{

					unlink( View($template_plaintext_view)->getPath() );
				}
				return true;
			}

		}

		return false;
	}

	static public function updateTemplate($request)
	{

		$template = DB::table(self::$templates_table)
		            ->where('template_slug', $request->templateslug)->first();

		if ( !is_null($template) ){


		if ( !preg_match( "/^[a-zA-Z0-9-_\s]+$/", $request->title ) ) {

			return response()->json([
					'status' => 'failed',
					'message' => 'Template name not valid',
			]);
		}


		$templatename = camel_case(preg_replace('/\s+/', '_', $request->title) );

		// check if not already exists on db
		// 
		// 

		if ( DB::table( self::$templates_table )->where('template_slug', '=', $templatename)->exists() ){

			return response()->json([

				'status' => 'failed',
				'message' => 'Template name already exists',

			]);
		}

		// Update
		// 
			DB::table(self::$templates_table)
				->where('template_slug', $request->templateslug)
				->update([
					'template_slug' => $templatename,
					'template_name' => $request->title,
					'template_description' => $request->description,
			]);

			$template_view = self::$view_namespace.'::templates.'.$request->templateslug;
			$template_plaintext_view = $template_view.'_plain_text';

			if ( View::exists( $template_view ) )
			{

				$viewPath = View($template_view)->getPath();

				rename($viewPath, dirname($viewPath)."/{$templatename}.blade.php");

				if ( View::exists( $template_plaintext_view ) ){

					$textViewPath = View($template_plaintext_view)->getPath();

					rename($textViewPath, dirname($viewPath)."/{$templatename}_plain_text.blade.php");
				}
			}

			return response()->json([

				'status' => 'ok',
				'message' => 'Updated Successfully',
				'template_url' => route('viewTemplate', [ 'templatename' => $templatename ]),

			]);

		}

	}

	static public function getTemplate($templateSlug)
	{

		$template = DB::table(self::$templates_table)
		            ->where('template_slug', $templateSlug)->first();

		if ( !is_null($template) ){

			$template_view = self::$view_namespace.'::templates.'.$template->template_slug;
			$template_plaintext_view = $template_view.'_plain_text';

			// return $template_plaintext_view;

			if ( View::exists( $template_view ) )
			{

				$viewPath = View($template_view)->getPath();
				$textViewPath = View($template_plaintext_view)->getPath();

				$templateData = collect([
					'template' => self::templateComponentReplace(file_get_contents($viewPath), true),
					'plain_text' => View::exists( $template_plaintext_view ) ? file_get_contents($textViewPath) : '',
					'slug' => $template->template_slug,
					'name' => $template->template_name,
					'description' => $template->template_description,
					'template_type' => $template->template_type,
					'template_view_name' => $template->template_view_name,
					'template_skeleton' => $template->template_skeleton,
				]);

				return $templateData;
			}
		}

		// return;
	}

	static public function getTemplates()
	{

		$template = DB::table(self::$templates_table)->get();

		return $template;
	}
	
	static public function createTemplate($request)
	{

		// preg_match('/^[a-zA-Z0-9_-\\s]+$/', 'dscsdc dscdsc')

		/*$validator = Validator::make($request->all(), [
		    'template_name' => 'required|regex:/^[a-zA-Z0-9_-\s]+$/u',
		    'template_description' => 'string|nullable',
		])->validate();


		if (!is_array($validator)) {
			if ($validator->fails()){
				return response()->json([ 
					'errors' => $validator->errors()->all() 
				]);
			}
		}*/

		if ( !preg_match( "/^[a-zA-Z0-9-_\s]+$/", $request->template_name ) ) {
			return response()->json([

					'status' => 'error',
					'message' => 'Template name not valid',

			]);
		}


		$view = mailEclipse::$view_namespace.'::templates.'.$request->template_name;

		$templatename = camel_case(preg_replace('/\s+/', '_', $request->template_name) );

		if ( !view()->exists($view) && !DB::table( self::$templates_table )->where('template_slug', '=', $templatename)->exists() )
		{

			DB::table(self::$templates_table)
		        ->insert([
		        	'template_name' => $request->template_name,
		        	'template_slug' => $templatename,
		        	'template_description' => $request->template_description,
		        	'template_type' => $request->template_type,
		        	'template_view_name' => $request->template_view_name,
		        	'template_skeleton' => $request->template_skeleton,
		        ]);

			$dir = dirname(__FILE__, 2). '/resources/views/templates';

			file_put_contents($dir. "/{$templatename}.blade.php", self::templateComponentReplace($request->content));

			file_put_contents($dir. "/{$templatename}_plain_text.blade.php", $request->plain_text);

			return response()->json([

					'status' => 'ok',
					'message' => 'Template created<br> <small>Reloading...<small>',
					'template_url' => route('viewTemplate', ['templatename' => $templatename]),

			]);
		}

		return response()->json([

					'status' => 'error',
					'message' => 'Template not created',

			]);
	}

	static public function getTemplateSkeletons()
	{

		return collect(config('maileclipse.skeletons'));
	}

	static public function getTemplateSkeleton($type, $name, $skeleton)
	{

		$skeletonView = self::$view_namespace."::skeletons.{$type}.{$name}.{$skeleton}";


		if ( view()->exists($skeletonView) )
		{

			$skeletonViewPath = View($skeletonView)->getPath();
			$templateContent = file_get_contents($skeletonViewPath);

			return [
				'type' => $type,
				'name' => $name,
				'skeleton' => $skeleton,
				'template' => self::templateComponentReplace($templateContent, true),
				'view' => $skeletonView,
				'view_path' => $skeletonViewPath,
			];
		}

	}
	
	static protected function templateComponentReplace($content, $reverse = false)
	{

		if ($reverse)
		{
			$patterns = [
				'/@component/i', 
				'/@endcomponent/i', 
				'/@yield/', 
				'/@section/', 
				'/@endsection/', 
				'/@extends/',
				'/@parent/',
				'/@slot/',
				'/@endslot/',
			];

			$replacements = [
				'[component]: # ', 
				'[endcomponent]: # ', 
				'[yield]: # ',
				'[section]: # ',
				'[endsection]: # ',
				'[extends]: # ',
				'[parent]: # ',
				'[slot]: # ',
				'[endslot]: # ',
			];

		} else 

		{
			$patterns = [
				'/\[component]:\s?#\s?/i', 
				'/\[endcomponent]:\s?#\s?/i', 
				'/\[yield]:\s?#\s?/i',
				'/\[section]:\s?#\s?/i',
				'/\[endsection]:\s?#\s?/i',
				'/\[extends]:\s?#\s?/i',
				'/\[parent]:\s?#\s?/i',
				'/\[slot]:\s?#\s?/i',
				'/\[endslot]:\s?#\s?/i',
			];

			$replacements = [
				'@component',
				'@endcomponent',
				'@yield',
				'@section',
				'@endsection',
				'@extends',
				'@parent',
				'@slot',
				'@endslot',
			];
		}

		return preg_replace($patterns, $replacements, $content);
	}
		
	static protected function markdownedTemplate($viewPath)
	{
		$viewContent = file_get_contents($viewPath);

		return self::templateComponentReplace($viewContent, true);

		// return preg_replace($patterns, $replacements, $viewContent);
	}

	/**
	 * 
	 * Markdowned template view
	 */

	static public function markdownedTemplateToView($save = true, $content = '', $viewPath = '', $template = false)
	{

		if ( $template && View::exists(self::$view_namespace.'::templates.'.$viewPath) )
		{

			$viewPath = View(self::$view_namespace.'::templates.'.$viewPath)->getPath();
		}

		$replaced = self::templateComponentReplace($content);

		if (!$save)
		{
			return $replaced;
		}

		return file_put_contents($viewPath, $replaced) === false ? false : true;
	}
	
	static public function previewMarkdownViewContent($simpleview = false, $content, $viewName, $template = false, $namespace = null)
	{

		$previewtoset = self::markdownedTemplateToView(false, $content);
		$dir = dirname(__FILE__, 2). '/resources/views/draft';
		$viewName = $template ? $viewName.'_template' : $viewName;

		if ( file_exists($dir) )
		{
			file_put_contents($dir. "/{$viewName}.blade.php", $previewtoset);
			$instance = $template ? null : new $namespace;
			return self::renderPreview($simpleview, self::$view_namespace.'::draft.'.$viewName, $template, $instance);
		}

		return false;
	}

	/**
	 * 
	 * 
	 */

	static public function previewMarkdownHtml($instance, $view){

		return self::renderPreview($instance, $view);
	}

	/**
	 * 
	 * 
	 */


	static public function getMailableTemplateData($mailableName)
	{
		$mailable = self::getMailable('name', $mailableName);

		if ($mailable->isEmpty()){

			return false;
		}


		$templateData = collect($mailable->first())->only(['markdown', 'view_path', 'text_view_path', 'text_view', 'view_data', 'data', 'namespace'])->all();

		$templateExists = !is_null($templateData['view_path']);
		$textTemplateExists = !is_null($templateData['text_view_path']);

		if ($templateExists)
		{

			$viewPathParams = collect($templateData)->union([

				'text_template' => $textTemplateExists ? file_get_contents($templateData['text_view_path']) : null,
				'template' => file_get_contents($templateData['view_path']),
				'markdowned_template' => self::markdownedTemplate($templateData['view_path']),
				'template_name' => !is_null($templateData['markdown']) ? $templateData['markdown'] : $templateData['data']->view,
				'is_markdown' => !is_null($templateData['markdown']) ? true : false,
				// 'text_template' => file_get_contents($templateData['text_view_path']),


			] )->all();

			return $viewPathParams;

		}

		return $templateData;

	}

	static public function generateMailable($request = null)
	{
		$name = ucwords( camel_case(preg_replace('/\s+/', '_', $request->input('name'))) );

		if ( !self::getMailable('name', $name)->isEmpty() && !$request->has('force') ){
			// return redirect()->route('createMailable')->with('error', 'mailable already exists! to overide it enable force option.');
			// 
			return response()->json([
					'status' => 'error',
					'message' => 'This mailable name already exists. names should be unique! to override it, enable "force" option.',
			]);

		}

		if (strtolower($name) === 'mailable'){
			return response()->json([
					'status' => 'error',
					'message' => 'You cannot use this name',
			]);
		}

		$params = collect([
			'name' => $name,
		]);

		if ($request->input('markdown')){
			$params->put('--markdown', $request->markdown);
		}

		if ($request->has('force')){
			$params->put('--force', true);
		}

		$exitCode = Artisan::call('make:mail', $params->all());

    	if ($exitCode > -1) {

    		// return redirect()->route('mailableList');
    		// 
    		return response()->json([

					'status' => 'ok',
					'message' => 'Mailable Created<br> <small>Reloading...<small>',

			]);
    	}
	}

	 /**
	  * Get mailables list
	  * 
	  * @return array
	  */

    static protected function mailablesList()
    {
	    $fqcns = array();

	    if ( !file_exists( config('maileclipse.mail_dir') ) ):

	    	return;

	    else:

	    $allFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator( config('maileclipse.mail_dir') ));
	    $phpFiles = new RegexIterator($allFiles, '/\.php$/');
		$i = 0;

	    foreach ($phpFiles as $phpFile) {
	    	$i++;
	        $content = file_get_contents($phpFile->getRealPath());
	        $tokens = token_get_all($content);
	        $namespace = '';
	        for ($index = 0; isset($tokens[$index]); $index++) {
	            if (!isset($tokens[$index][0])) {
	                continue;
	            }
	            if (T_NAMESPACE === $tokens[$index][0]) {
	                $index += 2; // Skip namespace keyword and whitespace
	                while (isset($tokens[$index]) && is_array($tokens[$index])) {
	                    $namespace .= $tokens[$index++][1];
	                }
	            }
	            if (T_CLASS === $tokens[$index][0] && T_WHITESPACE === $tokens[$index + 1][0] && T_STRING === $tokens[$index + 2][0]) {
	                $index += 2; // Skip class keyword and whitespace

	                list($name, $extension) = explode('.', $phpFile->getFilename());

	                $mailableClass = $namespace.'\\'.$tokens[$index][1];

	                if ( !self::mailable_exists($mailableClass)) {
	                	continue;
	                }

	                $mailable_view_data = self::getMailableViewData(new $mailableClass);
	                $mailable_data = self::buildMailable($mailableClass);

	                
	                
	                $fqcns[$i]['data'] = $mailable_data;
	                $fqcns[$i]['markdown'] = self::getMarkdownViewName($mailable_data);
	                $fqcns[$i]['name'] = $name;
	                $fqcns[$i]['namespace'] = $mailableClass;
	                $fqcns[$i]['filename'] = $phpFile->getFilename();
	                $fqcns[$i]['modified'] = $phpFile->getCTime();
	                $fqcns[$i]['viewed'] = $phpFile->getATime();
	                $fqcns[$i]['view_data'] = $mailable_view_data;
	                $fqcns[$i]['path_name'] = $phpFile->getPathname();
	                $fqcns[$i]['readable'] = $phpFile->isReadable();
	                $fqcns[$i]['writable'] = $phpFile->isWritable();
	                $fqcns[$i]['view_path'] = null;
	                $fqcns[$i]['text_view_path'] = null;

	                if ( !is_null($fqcns[$i]['markdown']) && View::exists($fqcns[$i]['markdown']) ){

	                	$fqcns[$i]['view_path'] = View($fqcns[$i]['markdown'])->getPath();
	                }

	                if ( !is_null($fqcns[$i]['data']) ) {

		                if ( !is_null($fqcns[$i]['data']->view) && View::exists($fqcns[$i]['data']->view) ){
		                	$fqcns[$i]['view_path'] = View($fqcns[$i]['data']->view)->getPath();
		                }

		                if (!is_null($fqcns[$i]['data']->textView) && View::exists($fqcns[$i]['data']->textView) ){
		                	$fqcns[$i]['text_view_path'] = View($fqcns[$i]['data']->textView)->getPath();
		                	$fqcns[$i]['text_view'] = $fqcns[$i]['data']->textView;
		                }

	            	}

	                # break if you have one class per file (psr-4 compliant)
	                # otherwise you'll need to handle class constants (Foo::class)
	                break;
	            }
	        }
	    }

	    $collection = collect($fqcns)->map(function( $mailable ){
	    	return $mailable;

	    })->reject(function($object){
	    	return !method_exists($object['namespace'], 'build');
		});

	    // return $collection->all();
	    // 
	    return $collection;

	endif;
    	
    }


    /**
	 * 
	 * 
	 */

    static private function getMailableViewData($mailable)
    {

        $traitProperties = [];

        $data = new ReflectionClass($mailable);

        foreach ($data->getTraits() as $trait) {
            foreach ($trait->getProperties(ReflectionProperty::IS_PUBLIC) as $traitProperty) {
                $traitProperties[] = $traitProperty->name;
            }
        }

        $properties = $data->getProperties(ReflectionProperty::IS_PUBLIC);
        $allProps = [];

        foreach ($properties as $prop) {
            if ($prop->class == $data->getName()) {
                $allProps[] = $prop->name;
            }
        }

        $obj = self::buildMailable($mailable);

        if ( is_null($obj) ){
        	$obj = [];

        	return collect($obj);
        }

        $classProps = array_diff($allProps, $traitProperties);
        
        $mailableData = collect($classProps)->merge( collect($obj->viewData)->keys() );

        return $mailableData->all();

    }

    /**
	 * 
	 * 
	 */

    static protected function mailable_exists($mailable)
    {

    	if ( !class_exists($mailable) )
    	{
    		return false;
    	}

    	return true;
    }

    /**
	 * 
	 * 
	 */

    static protected function getMarkdownViewName($mailable)
    {

    	if ($mailable === null){
    		return;
    	}

    	$reflection = new ReflectionClass($mailable);

	    $property = $reflection->getProperty('markdown');

	    $property->setAccessible(true);

	    return $property->getValue($mailable);
    }

    /**
	 * 
	 * 
	 */


    static public function buildMailable($instance, $type = 'call')
    {
    	if ($type == 'call'){
    		return Container::getInstance()->call( [ new $instance, 'build' ] );
    	}

    	return Container::getInstance()->make($instance);
    }

    /**
     *
     *
     * 
     */
 

    /*static public function previewTemplate($view, $data = []){
    	try {

    		$_md = self::buildMailable(Markdown::class, 'make');

	    	$renderer_html = $_md->render($view , $data);

	    	return $renderer_html;

	    } catch(ErrorException $e) {

	    	return '<div class="alert alert-warning">'.$e->getMessage().'</div>';
	    }
    }*/

    /**
	 * 
	 * 
	 */

    static public function renderPreview($simpleview = false, $view, $template = false, $instance = null)
    {
    	if ( !View::exists($view) ){
    		return;
    	}

    	 if(!$template){

    	 	$obj = self::buildMailable($instance);
	    	$viewData = $obj->viewData;
	    	$_data = array_merge($instance->buildViewData(), $viewData);

	    	foreach ($_data as $key => $value) {
		        $_data[$key] = '{{'.$key.'}}';
		    }

    	 } else {
    	 	$_data = [];
    	 }

	    $_view = $view;

	    try {

	    	if ($simpleview){

	    		$renderer_html = view($_view, $_data)->render();
	    		return $renderer_html;

	    	}

    		$_md = self::buildMailable(Markdown::class, 'make');
	    	$renderer_html = $_md->render($_view , $_data);

	    	return $renderer_html;

	    } catch(ErrorException $e) {

	    	$error = '<div class="alert alert-warning">
	    	<h5 class="alert-heading">Error:</h5>
	    	<p>'.$e->getMessage().'</p>
	    	</div>';

	    	if ($template){

	    		$error .= '<div class="alert alert-info">
				<h5 class="alert-heading">Notice:</h5>
				 <p>You can\'t add variables withing a template editor. because they are undefined until you bind template with a mailable that actually has data.</p>
	    	</div>';

	    	}

	    	return $error;
	    }

    }
}