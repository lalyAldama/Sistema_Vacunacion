<?php
require_once "../config/conexion.php";

//obtener id del usuario
$id_usuario = $_GET['id'];

$sql= "DELETE FROM USUARIO WHERE id_usuario=?";
$stmt= $conexion->prepare($sql);
$stmt->bind_param("i", $id_usuario);

if($stmt->execute()){
    header("Location: index.php");
    exit;
} else {
    echo "Error al eliminar usuario ". $stmt->error;

}
 
$stmt->close();
?>