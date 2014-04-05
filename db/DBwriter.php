<?php
require_once (__DIR__ . "/DBreader.php");

/**
 *  DBwriter class
 *
 *  This class provides basic methods for writing data into MySQL database.
 *
 *  @access public
 *  @author Yu Yagihashi
 */
class DBwriter {
  protected $link;
  protected $reader;

  public function __construct($host, $user, $pass, $db) {
    $this -> link = new mysqli($host, $user, $pass, $db);
    if ($this -> link -> connect_error) {
      return false;
    }
    $this -> link -> set_charset("utf8");

    // オートコミットオフ
    // transactQueryにクエリを投げる関数をぶん投げてあげるように
    $this -> link -> autocommit(false);

    $this -> reader = new DBreader($host, $user, $pass, $db);
  }

  public function __destruct() {
    $this -> link -> close();
  }

  protected function transactQuery(callable $func) {
    try {
      $this -> link -> begin_transaction();
      $func();
      $this -> link -> commit();
    } catch(Exception $e) {
      $this -> link -> rollback();
      return $e -> getMessage();
    }
  }

  /**
   * Thanks for http://www.akiyan.com/blog/archives/2011/07/php-mysqli-fetchall.html
   */
  function fetchAll(&$stmt) {
    $hits = array();
    $params = array();
    $meta = $stmt -> result_metadata();
    while ($field = $meta -> fetch_field()) {
      $params[] = &$row[$field -> name];
    }
    call_user_func_array(array($stmt, "bind_result"), $params);
    while ($stmt -> fetch()) {
      $c = array();
      foreach ($row as $key => $val) {
        $c[$key] = $val;
      }
      $hits[] = $c;
    }
    return $hits;
  }

  protected function updateData($id, $table, $column, $value) {
    $this -> transactQuery(function() {
      $table = $this -> link -> real_escape_string($table);
      $columns = $this -> link -> real_escape_string($column);
      $stmt = $this -> link -> prepare("UPDATE `$table` SET `$column`=? WHERE `id`=?");
      $stmt -> bind_param("si", $value, $id);
      $stmt -> execute();
      $stmt -> close();
      if ($stmt -> error !== "")
        throw new Exception("Failed to update data.");
    });
  }

  /**
   * ユーザを追加する。
   *
   * @param array $user login_name, name_ja, name_en, belongを含む連想配列
   */
  public function addUser($user) {
    $this -> transactQuery(function() {
      // ユーザ名のチェック → 英数字アンダースコア3-12文字 && 未使用
      if (!isset($user["login_name"])) {
        throw new Exception("Login name is required.");
      } else if (!preg_match("/^[a-zA-Z0-9]{3,12}$/", $user["login_name"])) {
        throw new Exception("Enter a valid login name");
      } else if ($this -> reader -> does_exist_user($user["username"])) {
        // TODO: あとで考える
      } else {
        $login_name = $user["login_name"];
      }

      // 氏名(日本語)のチェック
      if (!isset($user["name_ja"])) {
        $name_ja = "";
      } else if (strlen($user["name_ja"]) > 50) {
        throw new Exception("Name(ja) is too long.");
      } else {
        $name_ja = $user["name_ja"];
      }

      // 氏名(英語)のチェック
      if (!isset($user["name_en"])) {
        $name_en = "";
      } else if (strlen($user["name_en"]) > 50) {
        throw new Exception("Name(en) is too long.");
      } else {
        $name_en = $user["name_en"];
      }

      // 所属のチェック
      if (!isset($user["belong"])) {
        throw new Exception("The information about faculty/course which you belong to.");
      } else if (strlen($user["belong"]) > 50) {
        throw new Exception("Your faculty/course name is too long.");
      } else {
        $belong = $user["belong"];
      }

      $stmt = $this -> link -> prepare("INSERT INTO `users` (`login_name`, `name_ja`, `name_en`, `belong`) VALUES(?, ?, ?, ?)");
      $stmt -> bind_param("ssss", $login_name, $name_ja, $name_en, $belong);
      $stmt -> execute();
      $stmt -> close();
    });
  }

  /**
   * 論文を追加する。
   *
   * @param array $paper class, title_ja, title_en, file, description_ja, description_en, keywords, mailを含む連想配列
   */
  public function addPaper($paper) {
    $this -> transactQuery(function() {
      // 論文種別のチェック
      if (!isset($paper["class"])) {
        throw new Exception("The class of paper is required. Bachelar/Master/Doctor thesis or Other paper(with pear review or not)");
      } else {
        $class = $paper["class"];
      }

      // タイトル(日本語)のチェック
      if (!isset($paper["title_ja"])) {
        $title_ja = "";
      } else if (strlen($paper["title_ja"]) > 256) {
        throw new Exception("The Japanese title is too long.");
      } else {
        $title_ja = $paper["title_ja"];
      }

      // タイトル(英語)のチェック
      if (!isset($paper["title_en"])) {
        $title_en = "";
      } else if (strlen($paper["title_en"]) > 256) {
        throw new Exception("The English title is too long.");
      } else {
        $title_en = $paper["title_en"];
      }

      // ファイルのチェック
      // TODO: is_pdf, !is_array, もろもろ。受け渡しの方法も。

      // 概要(日本語)のチェック
      if (!isset($paper["description_ja"])) {
        $description_ja = "";
      } else if (strlen($paper["description_ja"]) > 2000) {
        throw new Exception("The Japanse description is too long.");
      } else {
        $description_ja = $paper["description_ja"];
      }

      // 概要(英語)のチェック
      if (!isset($paper["description_en"])) {
        $description_en = "";
      } else if (strlen($paper["description_en"]) > 2000) {
        throw new Exception("The English description is too long");
      } else {
        $description_en = $paper["description_en"];
      }

      // キーワードのチェック
      if (!isset($paper["keywords"])) {
        throw new Exception("Keywords are required.");
      } else if (count($paper["keywords"]) < 4) {
        throw new Exception("Four keywords are required at least.");
      } else if (count($paper["keywords"]) > 6) {
        throw new Exception("Too many keywords are posted.");
      } else {
        $keywords = serialize($paper["keywords"]);
      }

      // 連絡先のチェック
      if (!isset($paper["mail"])) {
        throw new Exception("Mail address is required.");
      } else if (strlen($paper["mail"]) > 256) {
        throw new Exception("Mail address is too long.");
      } else {
        $mail = $paper["mail"];
      }

      $stmt = $this -> link -> prepare("INSERT INTO `papers` (`class`, `title_ja`, `title_en`, `description_ja`, `description_en`, `keywords`, `mail`) VALUES(?, ?, ?, ?, ?, ?, ?)");
      $stmt -> bind_param("sssssss", $class, $title_ja, $title_en, $description_ja, $description_en, $keywords, $mail);
      $stmt -> execute();
      $stmt -> close();
    });
  }

}