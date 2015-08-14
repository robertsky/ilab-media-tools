<?php
require_once(ILAB_CLASSES_DIR.'/ilab-media-tool-base.php');
require_once(ILAB_VENDOR_DIR.'/autoload.php');

class ILabMediaImgixTool extends ILabMediaToolBase
{
    protected $imgixDomains;
    protected $signingKey;
    protected $imageQuality;
    protected $autoFormat;
    protected $paramPropsByType;
    protected $paramProps;

    public function __construct($toolName, $toolInfo, $toolManager)
    {
        parent::__construct($toolName, $toolInfo, $toolManager);
    }

    public function enabled()
    {
        $enabled=parent::enabled();

        if (!get_option('ilab-media-imgix-domains'))
        {
            $this->displayAdminNotice('error',"To start using Imgix, you will need to <a href='admin.php?page=media-tools-imgix'>set it up</a>.");
            return false;
        }

        return $enabled;
    }

    public function setup()
    {
        if (!$this->enabled())
            return;

        $this->paramProps=[];
        $this->paramPropsByType=[];
        if (isset($this->toolInfo['settings']['params']))
        {
            foreach($this->toolInfo['settings']['params'] as $paramCategory => $paramCategoryInfo)
                foreach($paramCategoryInfo as $paramGroup => $paramGroupInfo)
                    foreach($paramGroupInfo as $paramKey => $paramInfo)
                    {
                        $this->paramProps[$paramKey]=$paramInfo;

                        if (!isset($this->paramPropsByType[$paramInfo['type']]))
                            $paramType=[];
                        else
                            $paramType=$this->paramPropsByType[$paramInfo['type']];

                        $paramType[$paramKey]=$paramInfo;
                        $this->paramPropsByType[$paramInfo['type']]=$paramType;
                    }
        }

        $this->imgixDomains=[];
        $domains=get_option('ilab-media-imgix-domains');
        $domain_lines=explode("\n",$domains);
        foreach($domain_lines as $d)
            if (!empty($d))
                $this->imgixDomains[]=$d;

        $this->signingKey=get_option('ilab-media-imgix-signing-key');

        $this->imageQuality=get_option('ilab-media-imgix-default-quality');
        $this->autoFormat=get_option('ilab-media-imgix-auto-format');

        add_filter('wp_get_attachment_url', [$this, 'getAttachmentURL'], 10000, 2);
        add_filter('wp_prepare_attachment_for_js', array($this, 'prepareAttachmentForJS'), 1000, 3);

        add_filter('image_downsize', [$this, 'imageDownsize'], 1000, 3 );

        $this->hookupUI();

        add_action('admin_enqueue_scripts', [$this,'enqueueTheGoods']);
        add_action('wp_ajax_ilab_imgix_edit_page',[$this,'displayEditUI']);
        add_action('wp_ajax_ilab_imgix_save',[$this,'saveAdjustments']);
        add_action('wp_ajax_ilab_imgix_preview',[$this,'previewAdjustments']);


        add_action('wp_ajax_ilab_imgix_new_preset',[$this,'newPreset']);
        add_action('wp_ajax_ilab_imgix_save_preset',[$this,'savePreset']);
        add_action('wp_ajax_ilab_imgix_delete_preset',[$this,'deletePreset']);

    }

    public function registerSettings()
    {
        parent::registerSettings();

        register_setting('ilab-imgix-preset','ilab-imgix-presets');
        register_setting('ilab-imgix-preset','ilab-imgix-size-presets');
    }

    function prepareAttachmentForJS( $response, $attachment, $meta )
    {
        foreach($response['sizes'] as $key => $sizeInfo)
            $response['sizes'][$key]['url']=$this->buildImgixImage($response['id'],$key)[0];

        return $response;
    }

    public function getAttachmentURL($url, $post_id)
    {
        $url=$this->buildImgixImage($post_id,'full')[0];
        return $url;
    }

    private function buildImgixParams($params,$mimetype='')
    {
        if ($this->autoFormat && ($mimetype!='image/gif'))
            $auto='format';
        else
            $auto=null;

        if (($auto!=null) && isset($params['auto']))
        {
            $params['auto']=$params['auto'].','.$auto;
        }
        else if ($auto!=null)
        {
            $params['auto']=$auto;
        }

        unset($params['enhance']);
        unset($params['redeye']);

        if ($this->imageQuality)
            $params['q']=$this->imageQuality;

        foreach($this->paramPropsByType['media-chooser'] as $key=>$info)
        {
            if (isset($params[$key]) && !empty($params[$key]))
            {
                $media_id=$params[$key];
                unset($params[$key]);
                $markMeta=wp_get_attachment_metadata($media_id);
                $params[$info['imgix-param']]='/'.$markMeta['file'];
            }
            else
            {
                unset($params[$key]);
                if (isset($info['dependents']))
                    foreach($info['dependents'] as $depKey)
                        unset($params[$depKey]);
            }
        }

        if (isset($params['border-width']) && isset($params['border-color']))
        {
            $params['border']=$params['border-width'].','.str_replace('#','',$params['border-color']);
        }

        unset($params['border-width']);
        unset($params['border-color']);

        if (isset($params['padding-width']))
        {
            $params['pad']=$params['padding-width'];

            if (isset($params['padding-color']))
                $params['bg']=$params['padding-color'];
        }

        unset($params['padding-width']);
        unset($params['padding-color']);

        return $params;
    }

    private function buildImgixImage($id,$size, $params=null, $skipParams=false)
    {
        if (is_array($size))
            return false;

        $mimetype=get_post_mime_type($id);

        $meta=wp_get_attachment_metadata($id);

        $imgix=new Imgix\UrlBuilder($this->imgixDomains);

        if ($this->signingKey)
            $imgix->setSignKey($this->signingKey);

        if ($size=='full')
        {
            if (!$params)
            {
                if (isset($meta['imgix-params']))
                    $params=$meta['imgix-params'];
                else
                    $params=[];
            }

            $params=$this->buildImgixParams($params,$mimetype);


            $result=[
                $imgix->createURL(urlencode($meta['file']),($skipParams) ? [] : $params),
                $meta['width'],
                $meta['height'],
                false
            ];
            return $result;
        }

        $sizeInfo=ilab_get_image_sizes($size);
        if (!$sizeInfo)
            return false;

        $metaSize=null;
        if (isset($meta['sizes'][$size]))
        {
            $metaSize = $meta['sizes'][$size];
        }

        if (!$params)
        {
            // get the settings for this image at this size
            if (isset($meta['imgix-size-params'][$size]))
            {
                $params=$meta['imgix-size-params'][$size];
            }
            else // see if a preset has been globally assigned to a size and use that
            {
                $presets=get_option('ilab-imgix-presets');
                $sizePresets=get_option('ilab-imgix-size-presets');

                if ($presets && $sizePresets && isset($sizePresets[$size]) && isset($presets[$sizePresets[$size]]))
                    $params=$presets[$sizePresets[$size]]['settings'];
            }

            // still no parameters?  use any that may have been assigned to the full size image
            if ((!$params) && (isset($meta['imgix-params'])))
                $params=$meta['imgix-params'];
            else if (!$params) // too bad so sad
                $params=[];
        }

        if ($sizeInfo['crop'])
        {
            $params['w']=$sizeInfo['width'] ?: $sizeInfo['height'];
            $params['h']=$sizeInfo['height'] ?: $sizeInfo['width'];
            $params['fit']='crop';

            if ($metaSize)
            {
                $metaSize=$meta['sizes'][$size];
                if (isset($metaSize['crop']))
                {
                    $metaSize['crop']['x']=round($metaSize['crop']['x']);
                    $metaSize['crop']['y']=round($metaSize['crop']['y']);
                    $metaSize['crop']['w']=round($metaSize['crop']['w']);
                    $metaSize['crop']['h']=round($metaSize['crop']['h']);
                    $params['rect']=implode(',',$metaSize['crop']);
                }
            }
        }
        else
        {
            $newSize=sizeToFitSize($meta['width'],$meta['height'],$sizeInfo['width'] ?: 10000,$sizeInfo['height'] ?: 10000);
            $params['w']=$newSize[0];
            $params['h']=$newSize[1];
            $params['fit']='scale';
        }

        $params=$this->buildImgixParams($params,$mimetype);

        $result=[
            $imgix->createURL(urlencode($meta['file']),$params),
            $params['w'],
            $params['h'],
            false
        ];

        return $result;
    }

    public function imageDownsize($fail,$id,$size)
    {
        $result=$this->buildImgixImage($id,$size);
        return $result;
    }


    /**
     * Enqueue the CSS and JS needed to make the magic happen
     * @param $hook
     */
    public function enqueueTheGoods($hook)
    {
        add_thickbox();

        if ($hook == 'post.php')
            wp_enqueue_media();
        else if ($hook == 'upload.php')
        {
            $mode = get_user_option('media_library_mode', get_current_user_id()) ? get_user_option('media_library_mode', get_current_user_id()) : 'grid';
            if (isset($_GET['mode']) && in_array($_GET ['mode'], ['grid','list']))
            {
                $mode = $_GET['mode'];
                update_user_option(get_current_user_id(), 'media_library_mode', $mode);
            }

            if ($mode=='list')
            {
                $version = get_bloginfo('version');
                if (version_compare($version, '4.2.2') < 0)
                    wp_dequeue_script ( 'media' );

                wp_enqueue_media ();
            }
        }
        else
            wp_enqueue_style ( 'media-views' );

        wp_enqueue_style( 'wp-pointer' );
        wp_enqueue_style ( 'ilab-modal-css', ILAB_PUB_CSS_URL . '/ilab-modal.min.css' );
        wp_enqueue_style ( 'ilab-media-tools-css', ILAB_PUB_CSS_URL . '/ilab-media-tools.min.css' );
        wp_enqueue_script( 'wp-pointer' );
        wp_enqueue_script ( 'ilab-modal-js', ILAB_PUB_JS_URL. '/ilab-modal.js', ['jquery'], false, true );
        wp_enqueue_script ( 'ilab-media-tools-js', ILAB_PUB_JS_URL. '/ilab-media-tools.js', ['ilab-modal-js'], false, true );

    }

    /**
     * Hook up the "Edit Image" links/buttons in the admin ui
     */
    private function hookupUI()
    {

        add_action( 'wp_enqueue_media', function () {
            remove_action('admin_footer', 'wp_print_media_templates');

            add_action('admin_footer', function(){
                ob_start();
                wp_print_media_templates();
                $result=ob_get_clean();
                echo $result;

                ?>
                <script>
                    jQuery(document).ready(function() {
                        jQuery('input[type="button"]')
                            .filter(function() {
                                return this.id.match(/imgedit-open-btn-[0-9]+/);
                            })
                            .each(function(){
                                var image_id=this.id.match(/imgedit-open-btn-([0-9]+)/)[1];
                                var button=jQuery(this);
                                button.off('click');
                                button.attr('onclick',null);
                                button.on('click',function(e){
                                    e.preventDefault();

                                    ILabModal.loadURL("<?php echo relative_admin_url('admin-ajax.php')?>?action=ilab_imgix_edit_page&image_id="+image_id,false,null);

                                    return false;
                                });
                        });

                        attachTemplate=jQuery('#tmpl-attachment-details-two-column');
                        if (attachTemplate)
                        {
                            attachTemplate.text(attachTemplate.text().replace(/(<a class="(?:.*)edit-attachment(?:.*)"[^>]+[^<]+<\/a>)/,'<a href="<?php echo $this->editPageURL('{{data.id}}')?>" class="ilab-thickbox button edit-imgix"><?php echo __('Edit Image') ?></a>'));
                        }

                        attachTemplate=jQuery('#tmpl-attachment-details');
                        if (attachTemplate)
                            attachTemplate.text(attachTemplate.text().replace(/(<a class="(?:.*)edit-attachment(?:.*)"[^>]+[^<]+<\/a>)/,'<a class="ilab-thickbox edit-imgix" href="<?php echo $this->editPageURL('{{data.id}}')?>"><?php echo __('Edit Image') ?></a>'));
                    });
                </script>
                <?php
            } );
        } );
    }

    /**
     * Generate the url for the crop UI
     * @param $id
     * @param string $size
     * @return string
     */
    public function editPageURL($id, $size = 'full', $partial=false, $preset=null)
    {
        $url=relative_admin_url('admin-ajax.php')."?action=ilab_imgix_edit_page&image_id=$id";

        if ($size!='full')
            $url.="&size=$size";

        if ($partial===true)
            $url.='&partial=1';

        if ($preset!=null)
            $url.='&preset='.$preset;

        return $url;
    }


    /**
     * Render the edit ui
     */
    public function displayEditUI($is_partial=0)
    {
        $image_id = esc_html(parse_req('image_id'));
        $current_preset=esc_html(parse_req('preset'));

        $partial=parse_req('partial',$is_partial);

        $size=esc_html(parse_req('size','full'));

        $meta = wp_get_attachment_metadata($image_id);

        $attrs = wp_get_attachment_image_src($image_id, $size);
        list($full_src,$full_width,$full_height,$full_cropped)=$attrs;


        $imgix_settings=[];

        $presets=get_option('ilab-imgix-presets');
        $sizePresets=get_option('ilab-imgix-size-presets');


        $presetsUI=$this->buildPresetsUI($image_id,$size);



        if ($current_preset && $presets && isset($presets[$current_preset]))
        {
            $imgix_settings=$presets[$current_preset]['settings'];
            $full_src=$this->buildImgixImage($image_id,$size,$imgix_settings)[0];
        }
        else if ($size=='full')
        {
            if (!$imgix_settings)
            {
                if (isset($meta['imgix-params']))
                    $imgix_settings=$meta['imgix-params'];
            }
        }
        else
        {
            if (isset($meta['imgix-size-params'][$size]))
            {
                $imgix_settings=$meta['imgix-size-params'][$size];
            }
            else
            {
                if ($presets && $sizePresets && isset($sizePresets[$size]) && isset($presets[$sizePresets[$size]]))
                {
                    $imgix_settings=$presets[$sizePresets[$size]]['settings'];

                    if (!$current_preset)
                        $current_preset=$sizePresets[$size];
                }
            }

            if ((!$imgix_settings) && (isset($meta['imgix-params'])))
                $imgix_settings=$meta['imgix-params'];
        }

        foreach($this->paramPropsByType['media-chooser'] as $key=>$info)
        {
            if (isset($imgix_settings[$key]) && !empty($imgix_settings[$key]))
            {
                $media_id=$imgix_settings[$key];
                $imgix_settings[$key.'_url']=wp_get_attachment_url($media_id);
            }
        }

        if (current_user_can( 'edit_post', $image_id))
        {
            if (!$partial)
                echo \ILab\Stem\View::render_view('imgix/ilab-imgix-ui.php', [
                    'partial'=>$partial,
                    'image_id'=>$image_id,
                    'modal_id'=>gen_uuid(8),
                    'size'=>$size,
                    'sizes'=>ilab_get_image_sizes(),
                    'meta'=>$meta,
                    'full_width'=>$full_width,
                    'full_height'=>$full_height,
                    'tool'=>$this,
                    'settings'=>$imgix_settings,
                    'src'=>$full_src,
                    'presets'=>$presetsUI,
                    'currentPreset'=>$current_preset,
                    'params'=>$this->toolInfo['settings']['params'],
                    'paramProps'=>$this->paramProps
                ]);
            else
            {
                json_response([
                                  'status'=>'ok',
                                  'image_id'=>$image_id,
                                  'size'=>$size,
                                  'settings'=>$imgix_settings,
                                  'src'=>$full_src,
                                  'presets'=>$presetsUI,
                                  'currentPreset'=>$current_preset,
                                  'paramProps'=>$this->paramProps
                              ]);
            }
        }

        die;
    }

    /**
     * Save The Parameters
     */
    public function saveAdjustments()
    {
        $image_id = esc_html( $_POST['image_id'] );
        $size=esc_html($_POST['size']);
        $params=(isset($_POST['settings'])) ? $_POST['settings'] : [];

        if (!current_user_can('edit_post', $image_id))
            json_response([
                              'status'=>'error',
                              'message'=>'You are not strong enough, smart enough or fast enough.'
                          ]);


        $meta = wp_get_attachment_metadata( $image_id );
        if (!$meta)
            json_response([
                              'status'=>'error',
                              'message'=>'Invalid image id.'
                          ]);

        if ($size=='full')
        {
            $meta['imgix-params']=$params;
        }
        else
        {
            $meta['imgix-size-params'][$size]=$params;
        }

        wp_update_attachment_metadata($image_id, $meta);

        json_response([
                          'status'=>'ok'
                      ]);
    }

    /**
     * Preview the adjustment
     */
    public function previewAdjustments()
    {
        $image_id = esc_html( $_POST['image_id'] );
        $size=esc_html($_POST['size']);

        if (!current_user_can('edit_post', $image_id))
            json_response([
                              'status'=>'error',
                              'message'=>'You are not strong enough, smart enough or fast enough.'
                          ]);


        $params=(isset($_POST['settings'])) ? $_POST['settings'] : [];
        $result=$this->buildImgixImage($image_id,$size,$params);

        json_response(['status'=>'ok','src'=>$result[0]]);
    }

    private function buildPresetsUI($image_id,$size)
    {
        $presets=get_option('ilab-imgix-presets');
        if (!$presets)
            $presets=[];

        $sizePresets=get_option('ilab-imgix-size-presets');
        if (!$sizePresets)
            $sizePresets=[];

        $presetsUI=[];
        foreach($presets as $pkey => $pinfo)
        {
            $default_for='';
            foreach($sizePresets as $psize => $psizePreset)
            {
                if ($psizePreset == $pkey)
                {
                    $default_for = $psize;
                    break;
                }
            }

            $psettings=$pinfo['settings'];
            foreach($this->paramPropsByType['media-chooser'] as $mkey => $minfo)
            {
                if (isset($psettings[$mkey]))
                {
                    if (!empty($psettings[$mkey]))
                    {
                        $psettings[$mkey.'_url']=wp_get_attachment_url($psettings[$mkey]);
                    }
                }
            }

            $presetsUI[$pkey]=[
                'title'=>$pinfo['title'],
                'default_for'=>$default_for,
                'settings'=>$psettings
            ];
        }

        return $presetsUI;
    }

    /**
     * Update the presets
     * @param $key
     * @param $name
     * @param $settings
     * @param $size
     */
    private function doUpdatePresets($key,$name,$settings,$size,$makeDefault)
    {
        $image_id = esc_html( $_POST['image_id'] );
        $presets=get_option('ilab-imgix-presets');
        if (!$presets)
            $presets=[];

        $presets[$key]=[
            'title'=>$name,
            'settings'=>$settings
        ];
        update_option('ilab-imgix-presets',$presets);

        $sizePresets=get_option('ilab-imgix-size-presets');
        if (!$sizePresets)
            $sizePresets=[];

        if ($size && $makeDefault)
        {
            $sizePresets[$size]=$key;
        }
        else if ($size && !$makeDefault)
        {
            foreach($sizePresets as $s => $k)
            {
                if ($k==$key)
                    unset($sizePresets[$s]);
            }
        }

        update_option('ilab-imgix-size-presets',$sizePresets);

        json_response([
                          'status'=>'ok',
                          'currentPreset'=>$key,
                          'presets'=>$this->buildPresetsUI($image_id,$size)
                      ]);

    }

    /**
     * Create a new preset
     */
    public function newPreset()
    {
        $name = esc_html( $_POST['name'] );
        if (empty($name))
            json_response([
                              'status'=>'error',
                              'error'=>'Seems that you may have forgotten something.'
                          ]);

        $key=sanitize_title($name);
        $newKey=$key;

        $presets=get_option('ilab-imgix-presets');
        if ($presets)
        {
            $keyIndex=1;
            while(isset($presets[$newKey]))
            {
                $keyIndex++;
                $newKey=$key.$keyIndex;
            }
        }

        $settings=$_POST['settings'];
        $size=(isset($_POST['size'])) ? esc_html($_POST['size']) : null;
        $makeDefault=(isset($_POST['make_default'])) ? ($_POST['make_default']==1) : false;

        $this->doUpdatePresets($newKey,$name,$settings,$size,$makeDefault);

    }

    /**
     * Save an existing preset
     */
    public function savePreset()
    {
        $key = esc_html( $_POST['key'] );
        if (empty($key))
            json_response([
                              'status'=>'error',
                              'error'=>'Seems that you may have forgotten something.'
                          ]);

        $presets=get_option('ilab-imgix-presets');
        if (!isset($presets[$key]))
            json_response([
                              'status'=>'error',
                              'error'=>'Seems that you may have forgotten something.'
                          ]);

        $name=$presets[$key]['title'];
        $settings=$_POST['settings'];
        $size=(isset($_POST['size'])) ? esc_html($_POST['size']) : null;
        $makeDefault=(isset($_POST['make_default'])) ? ($_POST['make_default']==1) : false;

        $this->doUpdatePresets($key,$name,$settings,$size,$makeDefault);
    }

    /**
     * Delete an existing preset
     */
    public function deletePreset()
    {
        $image_id = esc_html( $_POST['image_id'] );
        $size = esc_html( $_POST['size'] );
        $key = esc_html( $_POST['key'] );
        if (empty($key))
            json_response([
                              'status'=>'error',
                              'error'=>'Seems that you may have forgotten something.'
                          ]);


        $presets=get_option('ilab-imgix-presets');
        if ($presets)
        {
            unset($presets[$key]);
            update_option('ilab-imgix-presets',$presets);
        }

        $sizePresets=get_option('ilab-imgix-size-presets');
        if (!$sizePresets)
            $sizePresets=[];

        foreach($sizePresets as $size=>$preset)
        {
            if ($preset==$key)
            {
                unset($sizePresets[$size]);
                break;
            }
        }

        update_option('ilab-imgix-size-presets',$sizePresets);

        return $this->displayEditUI(1);
//
//
//        json_response([
//                          'status'=>'ok',
//                          'preset_key'=>$key,
//                          'presets'=>$this->buildPresetsUI($image_id,$size)
//                      ]);
    }
}