<?php

header('Access-Control-Allow-Origin: *');

require_once "lib/simple_dom_html.php";

$conf = json_decode(file_get_contents('conf.json'));

try {
    $bdd = new PDO('mysql:host='.$conf->host.';dbname='.$conf->database, $conf->user, $conf->password);
    $bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $bdd->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
}
catch (Exception $e) {
    die('Erreur : ' . $e->getMessage());
}

function makeUTF8($string) {
	return $string;
}

$dbMedoc;
$openMedoc;

class OpenMedoc {

	public function getApiStatus() {
		$url = "https://www.open-medicaments.fr/api/v1/medicaments/info";

		$result = json_decode(file_get_contents($url));

		// DONT TAKE NEW DATE BUT STOCK THE PREVIOUS UPDATE DATE
		// 
		// 
		// 
		// 
		$lastUpdate = new \Datetime();
		$lastUpdate = $lastUpdate->format('d/m/Y');

		return $lastUpdate !== $result->dateMiseAJour;
	}

	// Get all medicaments from Open Medicaments API from given search
	public function getAll($query = 'a', $page = 1000, $limit = 100) {
		global $dbMedoc;
		$url = "https://www.open-medicaments.fr/api/v1/medicaments?query=".$query."&page=".$page."&limit=".$limit;

		$result = json_decode(file_get_contents($url));

		// var_dump(count($result)); die;

		foreach($result as $element) {
			$dbMedoc->insertMedoc($element->codeCIS, $element->denomination);
		}
	}

	// Get all medicaments from our DB which have just name and cis
	public function getMedocs() {
		global $dbMedoc;
		global $conf;
		$limit = 30;

		$medocs = $dbMedoc->getAllEmpty($limit);
		echo "got medocs<br>";

		if(empty($medocs)) {
			echo "no medoc empty";

			$page = isset($_GET['page']) ? $_GET['page'] : '1';
			$page = intval($page);
			$previouspage = isset($_GET['previouspage']) ? $_GET['previouspage'] : '1';
			$previouspage = intval($previouspage);
			$previouspage++;
			$page++;

			if(isset($_GET['previouspage']) && isset($_GET['page']) && $_GET['previouspage'] === $_GET['page']) {
				die;
			}

			echo "<script>location.href = '".$conf->url."?previouspage=".$previouspage."&page=".$page."';</script>";
			exit();
		}

		foreach ($medocs as $medoc) {
			$result = $this->getMedoc($medoc);
			echo $result;
			flush();
		    ob_flush();
		}

		echo "OVER";
		flush();
		ob_flush();

		echo "<script>location.reload();</script>";
		exit();
	}

	// Get one medicament from Open Medicaments API and update it in our database 
	public function getMedoc($medoc) {
		global $dbMedoc;

		echo $medoc['cis']." - ";

		$url = "https://www.open-medicaments.fr/api/v1/medicaments/".$medoc['cis'];

		$response = json_decode(file_get_contents($url));

		if(isset($response->formePharmaceutique)) {
			$medoc['forme'] = $response->formePharmaceutique;
		} else {
			$medoc['forme'] = $medoc['cis'];
		}

		// Denomination
		if(isset($response->compositions[0]) && isset($response->compositions[0]->substancesActives)) {
			$medoc['denomination'] = $response->compositions[0]->substancesActives[0]->denominationSubstance;
		} else {
			$medoc['denomination'] = $medoc['cis'];
		}

		$result = $dbMedoc->updateMedoc($medoc['cis'], makeUTF8($medoc['forme']), makeUTF8($medoc['denomination']), $this->getSideEffectsFromCIS($medoc['cis']));
		return $result;
	}

	// Get one medicament side effects from html scrapping because info doesn't exist in Open Medicaments API
	public function getSideEffectsFromCIS($cis) {

		echo $cis."<br>";

		$url = "http://base-donnees-publique.medicaments.gouv.fr/affichageDoc.php?specid=".$cis."&typedoc=R";
		$html = file_get_html($url);
		$sideEffect = "Aucun";

		if($html && isset($html->find('#textDocument')[0])) {

			$response = $html->find('#textDocument')[0]->children;

			$amm = "";
			$allowNext = false;
			$sideEffect = "";

			foreach ($response as $element) {

				if($allowNext) {

					if($element->class === "AmmAnnexeTitre2") {
						$allowNext = false;
						break;
					}

					$sideEffect .= $element->plaintext."<br />";
				}

				if($element->class === "AmmAnnexeTitre2" && isset($element->children[0]) && $element->children[0]->attr['name'] === "RcpEffetsIndesirables") {
					$allowNext = true;
				}
			}
		}


		return $sideEffect;
	}
}

class Medoc {

	public function insertMedoc($cis, $name) {
		global $bdd;

		if(!isset($cis)) {
			$cis = strval($_POST['cis']);
		}

		if(!isset($name)) {
			$name = $_POST['name'];
		}

		$exists = $this->getMedocFromCIS($cis);

		$status = "already";

		if(empty($exists)) {

			try {
				$insert = $bdd->prepare("INSERT INTO `medicaments` (`name`, `cis`, `denomination`, `side_effect`, `forme`, `indications`, `family`) VALUES (:name, :cis, '', '', '', '', 0);");
				
				$insert->bindParam(':cis', $cis, \PDO::PARAM_STR);
				$insert->bindParam(':name', $name, \PDO::PARAM_STR);
				$insert->execute();

				$status = "success";

			} catch (Exception $e) {
				echo $e->getMessage();
				die;
			}
		}

		$response = array(
			"status" => $status
		);

		echo json_encode($response);
	}

	public function updateMedoc($cis, $forme, $denomination, $side_effect = "") {
		global $bdd;

		if(!isset($forme)) {
			$forme = makeUTF8($_POST['forme']);
		}

		if(!isset($denomination)) {
			$denomination = makeUTF8($_POST['denomination']);
		}

		if(!isset($cis)) {
			$cis = $_POST['cis'];
		}

		try {
			
			$update = $bdd->prepare("UPDATE `medicaments` SET forme = :forme, denomination = :denomination, side_effect = :side_effect WHERE cis = ".$cis." ;");
			
			$update->bindParam(':forme', $forme, \PDO::PARAM_STR);
			$update->bindParam(':denomination', $denomination, \PDO::PARAM_STR);
			$update->bindParam(':side_effect', $side_effect, \PDO::PARAM_STR);
			$update->execute();

			return $cis. " updated <br>";

		} catch (Exception $e) {
			echo $e->getMessage();
		}
	}

	public function getMedocFromCIS($cis) {
		global $bdd;

		try {
			$get = $bdd->query("SELECT * FROM `medicaments` WHERE cis = ".$cis);
			$response = $get->fetchAll();

		} catch (Exception $e) {
			echo $e->getMessage();
			die;
		}

		return $response;
	}

	public function getAll($limit = 10) {
		global $bdd;

		// SELECT id, name, cis, denomination FROM `medicaments` WHERE forme = '' ORDER BY id asc LIMIT 0,".$limit

		try {
			$get = $bdd->query("SELECT * FROM `medicaments` ORDER BY id asc LIMIT 0,".$limit);
			$response = $get->fetchAll();

		} catch (Exception $e) {
			echo $e->getMessage();
			die;
		}

		// echo json_encode($response);

		return $response;
	}

	public function getAllEmpty($limit = 10) {
		global $bdd;

		try {
			$get = $bdd->query("SELECT * FROM `medicaments` WHERE forme = '' ORDER BY id asc LIMIT 0,".$limit);
			$response = $get->fetchAll();

		} catch (Exception $e) {
			echo $e->getMessage();
			die;
		}

		return $response;
	}

	public function cleanDB() {
		global $bdd;

		$query = "select count(id) as total, cis, id from medicaments group by cis order by total desc";

		try {
			$get = $bdd->query($query);
			$response = $get->fetchAll();

		} catch (Exception $e) {
			echo $e->getMessage();
			die;
		}

		// var_dump($response);

		foreach ($response as $res) {

			if($res['total'] > 1) {

				$query = "delete from medicaments where id != ".$res['id']." and cis = ".$res['cis'];

				try {
					$get = $bdd->query($query);
					// $response = $get->fetchAll();

				} catch (Exception $e) {
					echo $e->getMessage();
					die;
				}
			}
		}

		echo "Clean DB Done \n";
	}
}

$dbMedoc = new Medoc();
$openMedoc = new OpenMedoc();

if(isset($_GET['function'])) {

	if($_GET['function'] === "getMedocs") {
		echo json_encode($dbMedoc->getAll($_GET['limit']));
	}

	if($_GET['function'] === "getMedocsVersion") {
		echo json_encode($dbMedoc->getMedocsVersion());
	}

	if($_GET['function'] === "getEmptyMedocs") {
		echo json_encode(getAllEmptyMedicaments($_GET['limit']));
	}

	if($_GET['function'] === "updateMedoc") {
		// var_dump($_POST);
		updateMedoc();
	}




	if($_GET['function'] === "updateMedocDenomination") {
		// var_dump($_POST);
		updateMedocDenomination();
	}

	if($_GET['function'] === "updateMedocForme") {
		// var_dump($_POST);
		updateMedocForme();
	}

	

	if($_GET['function'] === "updateMedocSideEffect") {
		// var_dump($_POST);
		updateMedocSideEffect();
	}
}

function treatment($maxPages = 10) {
	global $dbMedoc;
	global $openMedoc;

	$dbMedoc->cleanDB();

	$majToDo = $openMedoc->getApiStatus();

	if($majToDo) {

		$page = isset($_GET['page']) ? $_GET['page'] : '1';
		$page = intval($page);

		if($page < $maxPages) {
			$openMedoc->getAll('a', $page, 100);
			$openMedoc->getMedocs();
		}

	} else {
		echo "Pas de mise à jour à faire depuis Open Medicaments";
	}
}

treatment(140);
