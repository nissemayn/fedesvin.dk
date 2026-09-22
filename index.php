<?php
$name = explode('.fedesvin.dk',$_SERVER['HTTP_HOST'])[0];
if($name == "fedesvin.dk") {
        echo "Prøv navn.fedesvin.dk i stedet...";
} else {

$name = str_replace('-',' ', $name);
?>
<center><br /><br />
<h2>Hej <?php echo ucfirst($name); ?></h2>
<h3>En eller anden synes åbenbart at du er et fedt svin!</h3>
<img src="https://c.tenor.com/TAOp_MAgovEAAAAC/baymax-big-hero6.gif">

</center>
<?php
file_put_contents('counter.txt', '1', FILE_APPEND);
echo '<!--Visits:' . filesize('counter.txt') . ' times!-->';

}
?>