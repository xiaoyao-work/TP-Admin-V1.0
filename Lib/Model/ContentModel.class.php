<?php
defined('THINK_PATH') or exit('No permission resources.');
define('MODEL_PATH',COMMON_PATH.'Admin'.DIRECTORY_SEPARATOR.'fields'.DIRECTORY_SEPARATOR);
class ContentModel extends Model {
  protected $autoCheckFields = false;
  protected $category, $modelid, $my_fields;

  public function set_model($modelid) {
    $model = M('Model')->where("siteid = %d and id = %d",get_siteid(),$modelid)->find();
    $this->modelid = $modelid;
    $this->trueTableName = C("DB_PREFIX").strtolower($model['tablename']);
    $this->set_field();
  }

  public function content_list($where=array(), $order = "id desc", $limit=20, $page_params = array()) {
    $news = $this->where(array_merge(array('siteid' => get_siteid()),$where))->order($order)->page((isset($_GET['p']) ? $_GET['p'] : 0).', '.$limit)->select();
    import("ORG.Util.Page");// 导入分页类
    $count = $this->where(array_merge(array('siteid' => get_siteid()),$where))->count();// 查询满足要求的总记录数
    $Page = new Page($count,$limit);// 实例化分页类 传入总记录数和每页显示的记录数
    if ($page_params) {
      foreach ($page_params as $key => $param) {
        $Page->setConfig($key, $param);
      }
    }
    $show = $Page->show();// 分页显示输出
    return array("data" => $news, "page" => $show);
  }

  public function mobile_content_list($where=array(), $order = "id desc", $limit=10, $page_params = array()) {
    $page_num = isset($_GET['p']) ? $_GET['p'] : 1;
    $news = $this->where(array_merge(array('siteid' => get_siteid()),$where))->order($order)->page($page_num .', '.$limit)->select();
    $news_temp = array();
    foreach ($news as $key => $value) {
      $value['url'] = U('Content/show',"catid=".$value['catid']."&id=".$value['id']);
      $news_temp[$value['id']] = $value;
    }
    unset($news);

    // 分页
    import("ORG.Util.Page");// 导入分页类
    $count = $this->where(array_merge(array('siteid' => get_siteid()),$where))->count();// 查询满足要求的总记录数
    $Page = new Page($count,$limit);// 实例化分页类 传入总记录数和每页显示的记录数
    if ($page_params) {
      foreach ($page_params as $key => $param) {
        $Page->setConfig($key, $param);
      }
    }
    $show = $Page->show();// 分页显示输出
    $next_page_num = ceil($count/$limit) - $page_num;

    return array('status' => 'success', 'message' => $news_temp, 'next_page_num' => $next_page_num, 'finished' => $finished, 'pages' => $show);
  }


  public function get_content( $id, $is_relation = false ) {
    $r = $this->where(array('id'=>$id))->find();
    $this->trueTableName = $this->trueTableName.'_data';
    $r2 = $this->where(array('id'=>$id))->find();
    if( !$r2 ) {
      if ($is_relation) {
        return false;
      }
      showmessage(L('subsidiary_table_datalost'),'blank');
    }
    $data = array_merge($r,$r2);
    return $data;
  }

  public function add_content() {
    // 主表
    $modelid = $this->modelid;
    $tablename = $this->trueTableName;
    $data = $_POST['info'];

    $data['relation'] = array2string($data['relation']);

    require MODEL_PATH.'content_input.class.php';
    $content_input = new content_input($this->modelid);
    $inputinfo = $content_input->get($data);
    $systeminfo = $this->parse_field($inputinfo['system']);
    $systeminfo = array_merge($systeminfo,array('username' => $_SESSION['user_info']['account'], 'siteid' => get_siteid(),'updatetime' => time(), 'status' => 99));
    if($data['inputtime'] && !is_numeric($data['inputtime'])) {
      $systeminfo['inputtime'] = strtotime($data['inputtime']);
    } elseif(!$data['inputtime']) {
      $systeminfo['inputtime'] = time();
    } else {
      $systeminfo['inputtime'] = $data['inputtime'];
    }
    $systeminfo['sysadd'] = defined('IN_ADMIN') ? 1 : 0;
    // $systeminfo = array_map('strip_tags', $systeminfo);
    if (($contentid = $this->add($systeminfo)) !== false) {
      //更新URL地址
      if($data['islink']==1) {
        $url = trim_script($_POST['linkurl']);
        $url = str_replace(array('select ',')','\\','#',"'"),' ',$urls[0]);
      } else {
        $siteinfo =  get_site_info($systeminfo['siteid']);
        $url = U( C("DEFAULT_GROUP") . '/Content/show@'.$siteinfo['url'] ,'catid='.$systeminfo['catid'].'&id='.$contentid);
        // $url = U('Content/show','catid='.$systeminfo['catid'].'&id='.$contentid);
      }
      $this->where("id = %d", $contentid)->save(array('url' => $url));

      // 附表
      $this->trueTableName = $this->trueTableName."_data";
      // $content_data = array('id' => $contentid ,'content' => $data['content'], 'relation' => $data['relation'], 'copyfrom' => $data['copyfrom'], 'allow_comment' => $data['allow_comment']);
      $this->set_field();
      $content_data = $this->parse_field($inputinfo['model']);
      $content_data['id'] = $contentid;
      $this->add($content_data);

      // 发布到推荐位
      if ($systeminfo['posids']) {
        foreach ($data['posids'] as $key => $posid) {
          if ($posid > 0) {
            $position_data = array('id' => $contentid, 'catid' => $systeminfo['catid'], 'posid' => $posid, 'modelid' => $modelid, 'module' => 'content', 'thumb' => $systeminfo['thumb'], 'siteid' => $systeminfo['siteid'], 'listorder' => $contentid, 'data' => array2string(array('title' => $systeminfo['title'], 'url' => $url, 'description' => $systeminfo['description'], 'inputtime' => $systeminfo['inputtime']), true));
            D("PositionData")->add($position_data);
          }
        }
      }
      // END 发布到推荐位

      //发布到其他栏目
      if($contentid && isset($_POST['othor_catid']) && is_array($_POST['othor_catid'])) {
        $linkurl = $url;
        foreach ($_POST['othor_catid'] as $cid=>$_v) {
          $this->set_catid($cid);
          $mid = $this->category[$cid]['modelid'];
          // var_dump($this->category);
          if($modelid==$mid) {
            //相同模型的栏目插入新的数据
            $systeminfo['catid'] = $cid;
            $newid = $content_data['id'] = $this->add($systeminfo);
            // echo $this->getLastSql();
            $this->trueTableName = $this->trueTableName.'_data';
            $this->add($content_data);
            if($data['islink']==1) {
              $url = $_POST['linkurl'];
              $url = str_replace(array('select ',')','\\','#',"'"),' ',$url);
            } else {
              $url = U( C("DEFAULT_GROUP") . '/Content/show','catid='.$systeminfo['catid'].'&id='.$newid);
            }
            $this->trueTableName = $tablename;
            $this->where(array('id'=>$newid))->save(array('url'=>$url));
          } else {
            //不同模型插入转向链接地址
            $systeminfo['catid'] = $cid;
            $systeminfo['url'] = $linkurl;
            $systeminfo['sysadd'] = 1;
            $systeminfo['islink'] = 1;
            $newid = $this->add($systeminfo);
            $this->trueTableName = $this->trueTableName.'_data';
            $this->add(array('id'=>$newid));
          }
        }
      }
      //END 发布到其他栏目
    }
    return $contentid;
  }

  public function edit_content() {
    $result = false;
    $modelid = $this->modelid;
    $contentid = intval($_POST['contentid']);
    $data = $_POST['info'];
    $data['relation'] = array2string($data['relation']);
    require MODEL_PATH.'content_input.class.php';
    $content_input = new content_input($this->modelid);
    $inputinfo = $content_input->get($data);
    // var_dump($inputinfo);
    $systeminfo = $this->parse_field($inputinfo['system']);
    // var_dump($systeminfo);
    // exit();
    $systeminfo = array_merge($systeminfo,array('updatetime' => time()));
    if($data['inputtime'] && !is_numeric($data['inputtime'])) {
      $systeminfo['inputtime'] = strtotime($data['inputtime']);
    } elseif(!$data['inputtime']) {
      $systeminfo['inputtime'] = time();
    } else {
      $systeminfo['inputtime'] = $data['inputtime'];
    }

    if($data['islink']==1) {
      $systeminfo['url'] = $_POST['linkurl'];
      $systeminfo['url'] = str_replace(array('select ',')','\\','#',"'"),' ',$systeminfo['url']);
    } else {
      $siteinfo =  get_site_info(get_siteid());
      $url = U( C("DEFAULT_GROUP") . '/Content/show@'.$siteinfo['url'] ,'catid='.$systeminfo['catid'].'&id='.$contentid);
      // $url = U('Content/show','catid='.$systeminfo['catid'].'&id='.$contentid);
      $systeminfo['url'] = $url;
    }

    if ($result = $this->where("id = %d", $contentid)->save($systeminfo) !== false) {
      // 附表
      $this->trueTableName = $this->trueTableName."_data";
      $this->set_field();
      $content_data = $this->parse_field($inputinfo['model']);
      $result = ($this->where("id = %d", $contentid)->save($content_data) !== false);

      // 发布到推荐位
      $position_model = D('PositionData');
      $position_model->where( array("id" => $contentid , "modelid" => $modelid) )->delete();
      if ($systeminfo['posids']) {
        foreach ($data['posids'] as $key => $posid) {
          if ($posid > 0) {
            $position_data = array('id' => $contentid, 'catid' => $systeminfo['catid'], 'posid' => $posid, 'modelid' => $modelid, 'module' => 'content', 'thumb' => $systeminfo['thumb'], 'siteid' => $systeminfo['siteid'], 'listorder' => $contentid, 'data' => var_export(array('title' => $systeminfo['title'], 'url' => $url, 'description' => $systeminfo['description'], 'inputtime' => $systeminfo['inputtime']), true));
            $position_model->add($position_data);
          }
        }
      }
      // END 发布到推荐位
    }
    return $result;
  }

  public function delete_content($ids) {
    if (is_array($ids)) {
      $result = (($this->where(array('id' => array('in', $ids)))->delete()) === false ? fasle : true);
      if ($result) {
        $this->trueTableName = $this->trueTableName.'_data';
        $this->where(array('id' => array('in', $ids)))->delete();
        D("PositionData")->where( array("id" => array('in', $ids), "modelid" => $this->modelid) )->delete();
      }
      return $result;
    } else {
      $result = ($this->where(array('id' => $ids))->delete()) === false ? fasle : true;
      if ($result) {
        $this->trueTableName = $this->trueTableName.'_data';
        $this->where(array('id' => $ids))->delete();
        D("PositionData")->where( array("id" => $ids, "modelid" => $this->modelid) )->delete();
      }
      return $result;
    }
  }

  /**
   * 设置catid 所在的模型数据库
   *
   * @param $catid
   */
  public function set_catid($catid) {
    $catid = intval($catid);
    if(!$catid) return false;
    if(empty($this->category)) {
      $categorys = D('Category')->select();
      foreach ($categorys as $key => $r) {
        $this->category[$r['id']] = $r;
      }
    }
    if(isset($this->category[$catid]) && $this->category[$catid]['type'] == 1) {
      $modelid = $this->category[$catid]['modelid'];
      $this->set_model($modelid);
    }
  }

  public function parse_field($options) {
    $temp = array();
    foreach ($this->my_fields as $key => $field) {
      if (isset($options[$field])) {
        $temp[$field] = $options[$field];
      }
    }
    return $temp;
  }

  public function set_field() {
    $fields = $this->query("DESC ".$this->trueTableName);
    $this->my_fields = array();
    foreach ($fields as $key => $value) {
      $this->my_fields[$key] = $value['Field'];
    }
  }
}
?>
