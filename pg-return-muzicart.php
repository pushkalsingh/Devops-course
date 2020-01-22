<?php
session_start();
include_once("db-connection/newconn.php");
date_default_timezone_set('Asia/Kolkata');
$orderid = mysqli_real_escape_string($dbhandle, $_SESSION['orderid']);
$userid = mysqli_real_escape_string($dbhandle, $_SESSION['userid']);
$customer_name = mysqli_real_escape_string($dbhandle, $_SESSION['customer_name']);

$curdate = date('Y-m-d H:i:s');

mysqli_query($dbhandle, "insert into trans_log (orderid, userid, datetime, description, entryby, status) values ('$orderid', '$userid', '$curdate', 'Returned from Juspay Payment Gateway', '$customer_name', 'unverified')");

$enc_regid = base64_encode($orderid);
if(!isset($_SESSION['orderid']) || (strlen($_SESSION['orderid'])==0))
{
	mysqli_query($dbhandle, "insert into trans_log (orderid, userid, datetime, description, entryby, status) values ('$orderid', '$userid', '$curdate', 'Order ID not found after returned from PG', '$customer_name', 'unverified')");
	
	$msg=base64_encode("fail");
	header("Location: fail?r=$enc_regid&msg=$msg");
	die();
}
else
{
	$resregdata=mysqli_query($dbhandle, "select * from temp_cart where orderid='$orderid'");
	$numregdata=mysqli_num_rows($resregdata);
	if($numregdata>0)
	{
		//Fetching Amount Details from DB
		$total_amt = 0;
		$total_qty = 0;
		$total_deliveryCharge = 0;
		while($fetcheckregid=mysqli_fetch_array($resregdata))
		{
			$offerprice=$fetcheckregid['offerprice'];
			$quantity = $fetcheckregid["quantity"];
			$delivery_charge = $fetcheckregid["delivery_charge"];
			$totaldeliveryCharge = ($quantity * $delivery_charge);				
			$total_deliveryCharge += $totaldeliveryCharge;
			$prdamount = ($offerprice * $quantity);
			$total_amt += ($prdamount + $total_deliveryCharge);
			$total_qty += $quantity;
		}

		if(isset($_REQUEST) && !empty($_REQUEST))
		{
			$pg_orderId = $_GET["order_id"];
			$merchantId = "MUZIC_SUPPORT";
			$ch = curl_init('https://api.juspay.in/order_status');
			curl_setopt($ch, CURLOPT_POSTFIELDS ,array('orderId' => $pg_orderId, 'merchantId' => $merchantId ));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
			curl_setopt($ch, CURLOPT_USERPWD, 'BAFB88AB03540E481039E19D1F2ECB:');

			//get the json response
			$jsonResponse =  json_decode( curl_exec($ch) );

			//Response received from Server Information
			$merchantId = $jsonResponse->{'merchantId'};
			$customerId = $jsonResponse->{'customerId'};
			$hash = $jsonResponse->{'id'};
			$customerEmail = $jsonResponse->{'customerEmail'};
			$customerPhone = $jsonResponse->{'customerPhone'};
			$currency = $jsonResponse->{'currency'};
			$pgorderid = $jsonResponse->{'orderId'};
			$trans_status = $jsonResponse->{'status'};
			$statusId = $jsonResponse->{'statusId'};
			$trans_amount = $jsonResponse->{'amount'};
			$refunded = $jsonResponse->{'refunded'};
			$amountRefunded = $jsonResponse->{'amount_refunded'};
			$return_url = $jsonResponse->{'return_url'};
			$paymentMethodType  = $jsonResponse->{'paymentMethodType'};
			$udf1 = $jsonResponse->{'udf1'};
			$udf2 = $jsonResponse->{'udf2'};
			$udf3 = $jsonResponse->{'udf3'};
			//. . skipping other udf fields
			$txnId = $jsonResponse->{'txnId'};
			$gatewayId = $jsonResponse->{'gatewayId'};
			$bankErrorCode = $jsonResponse->{'bankErrorCode'};
			$bankErrorMessage = $jsonResponse->{'bankErrorMessage'};
			##################################################################

			$pgnarration = "Returned from PG: Order.ID=$orderid | Calculated Amount=$total_amt | Amount Transacted=$trans_amount | Status=$trans_status";
			mysqli_query($dbhandle, "insert into trans_log (orderid, userid, datetime, description, entryby, status) values ('$orderid', '$userid', '$curdate', '$pgnarration', '$customer_name', '$trans_status')");

			//Inserting Into PGresponse
			mysqli_query($dbhandle, "INSERT INTO pgresponse(regid, customer_name, orderid, payid, pgtransid, response_code, authcode, currency_code, amount, status, message, email, phone, product_description, acqid, trans_type, hash, payment_type, return_url, dt) VALUES ('$orderid', '$customer_name - $customerId', '$pg_orderId', '', '$txnId', '$statusId', '', '$currency', '$trans_amount', '$trans_status', '$bankErrorCode - $bankErrorMessage', '$customerEmail', '$customerPhone', '', '$gatewayId', '', '$hash', '$paymentMethodType', '$return_url', '$curdate')");

			$pgnarration = "Amount detail inserted into PGresponse : Order.ID=$orderid | Trans Amount=$trans_amount | Amount= $total_amt | Status=$trans_status";
			$in = mysqli_query($dbhandle, "insert into trans_log (orderid, userid, datetime, description, entryby, status) values ('$orderid', '$userid', '$curdate', '$pgnarration', '$customer_name', '$trans_status')");

			// Checking Order.ID in Transaction Table
			$rescheckintrans=mysqli_query($dbhandle, "SELECT * from orders where orderid='$orderid' limit 1");
			$numchecktrans=mysqli_num_rows($rescheckintrans);
			if($numchecktrans==0)
			{
				$pgnarration = "Order ID does not Match : Reg.ID=$orderid | Amount=$trans_amount | Status=$trans_status";
				mysqli_query($dbhandle, "insert into trans_log (orderid, userid, datetime, description, entryby, status) values ('$orderid', '$userid', '$curdate', '$pgnarration', '$customer_name', 'already')");
				
				$msg=base64_encode("orderidmismatch");
				header("Location: fail?r=$enc_regid&msg=$msg");
				die();
			}
			elseif($total_amt!=$trans_amount)
			{
				$pgnarration = "Transacted amount not equal to calculated amount : Reg.ID=$orderid | Calculated Amount=$total_amt | Amount Transacted=$trans_amount | Status=$trans_status";
				mysqli_query($dbhandle, "insert into trans_log (orderid, userid, datetime, description, entryby, status) values ('$orderid', '$userid', '$curdate', '$pgnarration', '$customer_name', 'already')");
				
				$msg=base64_encode("amountmismatch");
				header("Location: fail?r=$enc_regid&msg=$msg");
				die();
			}
			else
			{
				$orderemail_rows = mysqli_fetch_array($rescheckintrans);
				$delivery_address = $orderemail_rows["delivery_address"];
				
				function genrate_rowid()
				{
					for($i=0; $i<6; $i++)
					{
						$d=rand(1,30)%2; 
						$d=$d ? chr(rand(65,90)) : chr(rand(48,57));
						$tmprowid=$tmprowid.$d;
					}

					$rquery_handle = mysqli_query($GLOBALS['dbhandle'], "select * from orderdetail where rowid='$tmprowid'");
					$rquerynum = mysqli_num_rows($rquery_handle);
					if($rquerynum>0)
					{
						return genrate_rowid();
					}
					else
					{
						return $tmprowid;
					}
				}	

				$pgnarration = "PG Hash : $hash";
				mysqli_query($dbhandle, "insert into trans_log (orderid, userid, datetime, description, entryby, status) values ('$orderid', '$userid', '$curdate', '$pgnarration', '$customer_name', '$trans_status')");
				
				if(($trans_status=='CHARGED') && ($orderid==$pg_orderId) && ($trans_amount>=$total_amt))
				{
					$pgnarration = "PG : Payment is completed successfully by $paymentMethodType : OrderID=$orderid | Amount=$total_amt";
					mysqli_query($dbhandle, "insert into trans_log (orderid, userid, datetime, description, entryby, status) values ('$orderid', '$userid', '$curdate', '$pgnarration', '$customer_name', 'CHARGED')");					
					$bodyemail = "";
					$totalgst = 0.0;
					$total_amount = 0;
					$total_delivery_charge = 0;
					$total_amount_less_gst = 0;
					$total_amount_plus_delivery = 0;
					$user = mysqli_query($dbhandle, "select * from delivery_address where rowid='$delivery_address' limit 1");
					$row = mysqli_fetch_array($user);
					$customer_mail = $row["email"];
					$address = ucwords($row["address"]);
					$deliveredto = ucwords($row["name"]);
					$locality = ucwords($row["locality"]);
					$city = ucwords($row["city"]);
					$state = ucwords($row["state"]);
					$pincode = $row["pincode"];
					$mobile_num = $row["mobile"];
					$altmobile = $row["altmobile"];
					if($altmobile != ''){
						$altmobile = ", $altmobile";
					}else{
						$altmobile = "";
					}
					$mobile_number = $mobile_num.$altmobile;

										
					$bodyemail .="<!DOCTYPE html>
					<html lang='en'>
					<head>
        

						<style type='text/css'>
							.mail_body{
								text-align: center;
								margin: 0 auto;
								width: 650px;
								font-family: 'Open Sans', sans-serif;
								background-color: #e2e2e2;		      	
								display: block;
							}
							a{
								text-decoration: none;
							}
							p{
								margin: 15px 0;
							}

							h5{
								color:#444;
								text-align:left;
								font-weight:400;
							}
							.text-center{
								text-align: center
							}
							.main-bg-light{
								background-color: #fafafa;
							}
							.title{
								color: #444444;
								font-size: 22px;
								font-weight: bold;
								margin-top: 10px;
								margin-bottom: 10px;
								padding-bottom: 0;
								text-transform: uppercase;
								display: inline-block;
								line-height: 1;
							}
							table{
								margin-top:30px
							}
							table.top-0{
								margin-top:0;
							}
							table.order-detail , .order-detail th , .order-detail td {
								border: 1px solid #ddd;
								border-collapse: collapse;
							}
							.order-detail th{
								font-size:16px;
								padding:15px;
								text-align:center;
							}
							.footer-social-icon tr td img{
								margin-left:5px;
								margin-right:5px;
							}
						</style>
					</head>
					
					
					<body class='mail_body' style='margin: 20px auto;'>
					<table align='center' border='0' cellpadding='0' cellspacing='0' style='padding: 0 30px;background-color: #fff; -webkit-box-shadow: 0px 0px 14px -4px rgba(0, 0, 0, 0.2705882353);box-shadow: 0px 0px 14px -4px rgba(0, 0, 0, 0.2705882353);width: 100%;'>
					<tbody>
					<tr>
						<td>
                        <table align='center' border='0' cellpadding='0' cellspacing='0' >
                        <tr>
							<td>
								<img src='https://www.muzicart.com/images/logo/muzicart-Logo.png' alt='' style=';margin-bottom: 20px;width:250px;'>
                            </td>
                        </tr>
                        <tr>
                            <td>
								<img src='https://www.muzicart.com/images/success.png' alt=''>
                                   <!-- <img src='images/delivery.gif' alt=''>-->
                            </td>
                        </tr>
                        <tr>
                            <td>
								<h2 class='title'>Thank you for your order</h2>
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <p style='margin:0;font-size:16px;'>We are processing your order and you will be shortly updated with the delivery details.</p>
                                <p style='color:#f00;font-size:16px; font-weight:600;margin:0;'>Your Order ID: $orderid</p>
                            </td>
                        </tr>
						</table>
                        
                    <table class='order-detail' border='0' cellpadding='0' cellspacing='0'  align='center' style='margin-top:10px;'>
						<tr>
							<td colspan='4'>
                                    <h2 class='title'>YOUR ORDER DETAILS</h2>
                            </td>
                        </tr>
                        <tr align='center'>
                            <th>PRODUCT</th>
                            <th style='padding:0 15px;'>DESCRIPTION</th>
                            <th>QTY</th>
                            <th>PRICE </th>
                        </tr>";
						
					$tmpcart_handler = mysqli_query($dbhandle, "select * from temp_cart where userid='$userid' and orderid='$orderid'");
					if(($nums = mysqli_num_rows($tmpcart_handler))>0)
					{
						$count=0;
						$prodlist="";
						$tdiscount=0;
					 while($tmpcart_rows = mysqli_fetch_array($tmpcart_handler))
					 {
						$count++;
						$inc = ($count%2);
						if($inc==0)
						{
							$bgcolor = "#afafaf";
						}
						else
						{
							$bgcolor = "#ffffff";
						}
						$rowid = $tmpcart_rows["rowid"];
						$orderid = $tmpcart_rows["orderid"];
						$userid = $tmpcart_rows["userid"];
						$prodid = $tmpcart_rows["prodid"];
						$quantity = $tmpcart_rows["quantity"];
						$datetime = $tmpcart_rows["datetime"];
						$vendorid = $tmpcart_rows["vendorid"];
						$price = $tmpcart_rows["price"];
						$offerprice = $tmpcart_rows["offerprice"];
						$discount = $tmpcart_rows["discount"];
						$delivery_charge = $tmpcart_rows["delivery_charge"];
						
						$amount_total = ($offerprice * $quantity);
						$totaldelivery_charge = ($delivery_charge * $quantity);
						$total_amount += $amount_total;
						$tdiscount+=$discount;
						$total_delivery_charge += $totaldelivery_charge;
						$subtotal = $amount_total + $totaldelivery_charge;
						$total_amount_plus_delivery+=$subtotal;
						
						//fetching state of seller
						$statehandler = mysqli_query($dbhandle,"SELECT * FROM setting WHERE item='origin_state'");
						$statenum = mysqli_num_rows($statehandler);
						if($statenum>0)
						{
							$statefetch = mysqli_fetch_array($statehandler);
							$seller_state = $statefetch['value'];
						}
						
						// Calculation of GST
						$igst = $tmpcart_rows["igst"];
						$cgst = $tmpcart_rows["cgst"];
						$sgst = $tmpcart_rows["sgst"];
						if($state==ucwords($seller_state))//cgst & sgst applicable intra state
						{
							$igst=0.0;
						}
						else //igst applicable inter state
						{
							$cgst = 0.0;
							$sgst = 0.0;
						}
						
						$productgst = $igst + $cgst + $sgst ;
						$totalgst += $productgst;
						
						$amount_less_gst = $amount_total - $productgst ;
						$total_amount_less_gst+= $amount_less_gst ;
						
						
						$prodrows = mysqli_fetch_array(mysqli_query($dbhandle, "select product_name, thumb_image, shortdesc from product where prodid='$prodid'"));
						$productname = ucwords($prodrows["product_name"]);
						$thumb_image = $prodrows["thumb_image"];
						$shortdesc = ucwords($prodrows["shortdesc"]);
						$prod_image = "https://www.muzicart.com/images/productimage/$prodid/$thumb_image";
						$disp_imgpath = "<img src='$prod_image' />";
						
						$prodlist.= $productname.", ";
						
						
						$bodyemail.="<tr>                               
							<td >
								<img src='$prod_image' alt='' width='70' style='padding:5px;'>
                            </td>
                            <td valign='top' style='padding: 0 15px;'>
								<h5 style='margin-top: 10px;font-size:15px;text-align:center'><b>$shortdesc</b> 
								</h5>
                            </td>
                            <td valign='top' style=''>
                                <!--<h5 style='font-size: 14px; color:#444;margin-top:15px;    margin-bottom: 0px;'>Size : <span> L</span> </h5>-->
                                <h5 style='font-size: 14px; color:#444;margin-top: 10px;text-align:center'> <span>$quantity</span></h5>                                    
                            </td>
                            <td valign='top' style=''>
								<h5 style='font-size: 14px; color:#444;margin-top:10px;text-align:center'>&#8377;<b>$amount_total</b></h5>                  
                            </td>
                        </tr>";
						
						$orddet_handler = mysqli_query($dbhandle, "select * from orderdetail where orderid='$orderid' and prodid='$prodid' and userid='$userid'");
						$orddet_num = mysqli_num_rows($orddet_handler);
						if($orddet_num==0)
						{
							$orddet_rowid = genrate_rowid();
							mysqli_query($dbhandle, "insert into orderdetail (rowid, vendorid, orderid, userid, prodid, quantity, price, offerprice, discount, delivery_charge, status,igst,cgst,sgst) values ('$orddet_rowid', '$vendorid', '$orderid', '$userid', '$prodid', '$quantity', '$price', '$offerprice', '$discount', '$delivery_charge', 'Success','$igst','$cgst','$sgst')");
						}
					 }
					}
					
					//fetching state of seller
					$shandler = mysqli_query($dbhandle,"SELECT * FROM setting WHERE item='origin_state'");
					$snum = mysqli_num_rows($shandler);
					if($snum>0)
					{
						$sfetch = mysqli_fetch_array($shandler);
						$origin_state = $sfetch['value'];
					}
					
					if($state==ucwords($origin_state))
					{
						$netgst = $totalgst/2;
						$gstvar ="
						<tr>
							<td colspan='2' style='line-height: 35px;font-size: 13px;color: #000000;padding-left: 20px;text-align:right;border-right: unset;'>CGST</td>
                            <td colspan='3' class='price' style='line-height: 35px;text-align: right;padding-right: 28px;font-size: 13px;color: #000000;text-align:right;border-left: unset;'>&#8377;<b>$netgst</b></td>
                        </tr>
						<tr>
							<td colspan='2' style='line-height: 35px;font-size: 13px;color: #000000;padding-left: 20px;text-align:right;border-right: unset;'>SGST</td>
                            <td colspan='3' class='price' style='line-height: 35px;text-align: right;padding-right: 28px;font-size: 13px;color: #000000;text-align:right;border-left: unset;'>&#8377;<b>$netgst</b></td>
                        </tr>";

					}
					else
					{
						$gstvar ="
						<tr>
							<td colspan='2' style='line-height: 35px;font-size: 13px;color: #000000;padding-left: 20px;text-align:right;border-right: unset;'>IGST</td>
                            <td colspan='3' class='price' style='line-height: 35px;text-align: right;padding-right: 28px;font-size: 13px;color: #000000;text-align:right;border-left: unset;'>&#8377;<b>$totalgst</b></td>
                        </tr>";
					}
					
					$deliveryhandler = mysqli_query($dbhandle,"SELECT * FROM orders WHERE orderid='$orderid'");
					$deliverynum = mysqli_num_rows($deliveryhandler);
					if($deliverynum>0)
					{
						$deliveryfetch = mysqli_fetch_array($deliveryhandler);
						$deliveryid = $deliveryfetch['delivery_address'];
						$dahandler = mysqli_query($dbhandle,"SELECT * FROM delivery_address WHERE rowid='$deliveryid'");
						$danum = mysqli_num_rows($dahandler);
						if($danum>0)
						{
							$dafetch = mysqli_fetch_array($dahandler);
							$daname = $dafetch['name'];
							$daddress = $dafetch['address'];
							$dacity = $dafetch['city'];
							$dastate = $dafetch['state'];
							$dapincode = $dafetch['pincode'];
							$damobile = $dafetch['mobile'];
						}
					}
					
					function sendsms($sms_product_list,$ordid,$ordamount,$mobnum)
					{
						$msg="Order Placed. Your order for $sms_product_list with order id $ordid  amounting Rs. $ordamount has been sucessfully received. We will send you an update when your order is packed / shipped. muzicart.com";
						$serverUrl = "sms.insidesoftwares.com";
						$senderId="MUZCRT";
						$routeId="1";
						$authKey="2f25bf2257d6c552364ac3314786aeb5";
						$getData = 'mobileNos='.$mobnum.'&message='.urlencode($msg).'&senderId='.$senderId.'&routeId='.$routeId;
						$url="http://".$serverUrl."/rest/services/sendSMS/sendGroupSms?AUTH_KEY=".$authKey."&".$getData;		
						//init the resource
						$ch = curl_init();
						curl_setopt_array($ch, array(
							CURLOPT_URL => $url,
							CURLOPT_RETURNTRANSFER => true,
							CURLOPT_SSL_VERIFYHOST => 0,
							CURLOPT_SSL_VERIFYPEER => 0
						));
						curl_exec($ch);
					}
					
					function sendsmsadmin($sms_product_list,$ordid,$ordamount,$mobnum)
					{
						$msg="A new order for $sms_product_list with order id $ordid  amounting Rs. $ordamount has been received. Please check the admin dashboard for details.";
						$serverUrl = "sms.insidesoftwares.com";
						$senderId="MUZCRT";
						$routeId="1";
						$authKey="2f25bf2257d6c552364ac3314786aeb5";
						$getData = 'mobileNos='.$mobnum.'&message='.urlencode($msg).'&senderId='.$senderId.'&routeId='.$routeId;
						$url="http://".$serverUrl."/rest/services/sendSMS/sendGroupSms?AUTH_KEY=".$authKey."&".$getData;		
						//init the resource
						$ch = curl_init();
						curl_setopt_array($ch, array(
							CURLOPT_URL => $url,
							CURLOPT_RETURNTRANSFER => true,
							CURLOPT_SSL_VERIFYHOST => 0,
							CURLOPT_SSL_VERIFYPEER => 0
						));
						curl_exec($ch);
					}
					
					
					
					
					mysqli_query($dbhandle, "update orders set order_date='$curdate', delivery_charges='$total_delivery_charge', modeof_payment='$paymentMethodType', status='Success',amount='$trans_amount',gst='$totalgst',cod='0' where orderid='$orderid' and status='initiated'");
					
					$del = mysqli_query($dbhandle, "delete from temp_cart where userid='$userid' and orderid='$orderid'");
					$msg=base64_encode("done");
					$all_total_amount = $total_amount + $total_delivery_charge;
					if($total_delivery_charge == '0'){
						$total_delivery_charge = "FREE";
					}else{
						$total_delivery_charge = "&#8377;".$total_delivery_charge;
					}
					
					$gstnohandler = mysqli_query($dbhandle,"SELECT * FROM setting WHERE item='gstno'");
					$gstnum = mysqli_num_rows($gstnohandler);
					if($gstnum>0)
					{
						$gstfetch = mysqli_fetch_array($gstnohandler);
						$gstnumber = $gstfetch['value'];
					}
					
					
					
					
					#send mail							
					
			$bodyemail.="<tr>
                            <td colspan='2' style='line-height:35px;font-size: 13px;color: #000000;padding-left: 20px;text-align:right;border-right: unset;'>Product(s) Total </td>
                            <td colspan='3' class='price' style='line-height: 35px;text-align: right;padding-right: 28px;font-size: 13px;color: #000000;text-align:right;border-left: unset;'>&#8377;<b>$total_amount_less_gst</b></td>
                        </tr>
                        $gstvar
                        <!--<tr>
                            <td colspan='2' style='line-height: 35px;font-family: Arial;font-size: 13px;color: #000000;padding-left: 20px;text-align:right;border-right: unset;'>Gift Wrapping </td>
                            <td colspan='3' class='price' style='line-height: 35px;text-align: right;padding-right: 28px;font-size: 13px;color: #000000;text-align:right;border-left: unset;'>&#8377; <b></b></td>
                        </tr>-->
                        <tr>
                            <td colspan='2' style='line-height: 35px;font-size: 13px;color: #000000; padding-left: 20px;text-align:right;border-right: unset;'>Shipping 
							</td>
                            <td colspan='3' class='price' style='line-height: 35px;text-align: right;padding-right: 28px;font-size: 13px;color: #000000;text-align:right;border-left: unset;'><b>$total_delivery_charge	</b>
							</td>
                        </tr>
						<tr>
                            <td colspan='2' style='line-height: 35px;font-size: 13px;color: #000000;padding-left: 20px;text-align:right;border-right: unset;'>TOTAL AMOUNT 
							</td>
                            <td colspan='3' class='price' style='line-height: 35px;text-align: right;padding-right: 28px;font-size: 13px;color: #000000;text-align:right;border-left: unset;'>&#8377;<b>$total_amount_plus_delivery</b>
							</td>
                        </tr>
                        <tr>
                            <td colspan='2' style='line-height: 35px;font-size: 13px;color: #000000;padding-left: 20px;text-align:right;border-right: unset;'>TOTAL PAID 
							</td>
                            <td colspan='3' class='price' style='line-height: 35px;text-align: right;padding-right: 28px;font-size: 13px;color: #000000;text-align:right;border-left: unset;'>&#8377;<b>$trans_amount</b>
							</td>
                        </tr>
                    </table>
                    <table cellpadding='0' cellspacing='0' border='0' align='left' style='width: 100%;margin-top: 30px;    margin-bottom: 15px;'>
					<tbody>
						<tr>
							<!--<td style='font-size: 13px; font-weight: 400; color: #444444; letter-spacing: 0.2px;width: 50%;'>
							<td>
								<h5 style='font-size: 16px; font-weight: 500;color: #000; line-height: 16px; padding-bottom: 13px; border-bottom: 1px solid #e6e8eb; letter-spacing: -0.65px; margin-top:0; margin-bottom: 13px;'>DELIVERY ADDRESS</h5>
								<p style='text-align: left;font-weight: normal; font-size: 14px; color: #000000;line-height: 21px;    margin-top: 0;'>268 Cambridge Lane New Albany,<br> IN 47150268 Cambridge Lane <br>New Albany, IN 47150</p>
							</td>
							<td width='57' height='25' class='user-info'><img src='http://www.muzicart.com/images/space.jpg' alt='' height='25' width='57'></td>-->
							<td  class='user-info' style='font-size: 13px; font-weight: 400; color: #444444; letter-spacing: 0.2px;width: 50%;'>
								<h5 style='text-align:center;font-size: 16px;font-weight: 600;color: #000; line-height: 16px; padding-bottom: 5px; border-bottom: 1px solid #e6e8eb; margin-top:0; margin-bottom: 0;'>SHIPPING ADDRESS</h5>
								<address style='text-align: center;font-weight: normal; font-size: 14px; color: #000000;line-height: 21px;    margin-top: 5px;'><b>$daname</b> <br />268 $daddress,<br />$dacity <br /> $dastate - $dapincode<br />$damobile</address>
							</td>
						</tr>
						
					</tbody>
				</table> 
               </td>
             </tr>
           </tbody>            
        </table>
        <table class='main-bg-light text-center top-0'  align='center' border='0' cellpadding='0' cellspacing='0' width='100%'>
			<tr>
				<td style='padding: 30px 30px 15px 30px;'>
				<div>
					<h4 class='title' style='margin:0;text-align: center;'>Follow us</h4>
				</div>
				<table border='0' cellpadding='0' cellspacing='0' class='footer-social-icon' align='center' class='text-center' style='margin-top:20px;'>
					
					<tr>
						<td>
							<a href='#'><img src='http://www.muzicart.com/images/facebook.png' alt='' style='width:40px;'></a>
						</td>
						<td>
							<a href='#'><img src='http://www.muzicart.com/images/instagram.png' alt='' style='width:40px;'></a>
						</td>
						<!--<td>
							<a href='#'><img src='http://www.muzicart.com/images/youtube.png' alt='' style='width:40px;'></a>
						</td>-->
					</tr> 
					<tr>
							<td colspan='2' class='user-info' style='text-align:center;font-size: 10px; font-weight: 400; color: #444444; letter-spacing: 0.2px;'><div style='text-align:center;display:inline;font-size: 10px;font-weight:normal;color: #000; line-height: 16px; margin-top:0; margin-bottom: 0;'>GST Number : $gstnumber</div>
							</td>
					</tr>
						
				</table>
				<div style='border-top: 1px solid #ddd; margin: 20px auto 0;'></div>
				<table  border='0' cellpadding='0' cellspacing='0' width='100%' style='margin: 20px auto 0;' >
					<tr>
						<td>
							<p style='font-size:13px; margin:0;'>2019  &copy; Copyright Muzicart Design &amp; Developed by 
								<a href='https://www.insidesoftwares.com/' style='color:#000000;'>Inside Softwares</a></p>
						</td>
					</tr>
					<!--<tr>
						<td>
							<a href='#' style='font-size:13px; margin:0;text-decoration: underline;'>Unsubscribe</a>
						</td>
					</tr>-->
				</table>
			</td>
		</tr>
	</table>
   </body>
   </html>";
					
					//email code 
					require 'phpmailer/class.phpmailer.php';
					require 'phpmailer/class.smtp.php';
					require 'phpmailer/class.pop3.php';

					$mail = new PHPMailer;
					$mail->From = 'info@muzicart.com';
					$mail->FromName = 'Muzicart';
					$mail->addAddress($customer_mail, $customer_name); 
					$mail->WordWrap = 50;      
					$mail->isHTML(true);                                  // Set email format to HTML

					$mail->Subject = 'Muzicart - Order Received';
					$mail->Body    = $bodyemail;
					$mail->AltBody = 'This is the body in plain text for non-HTML mail clients';

					if(!$mail->send()) {
					echo 'Message could not be sent.';
					echo 'Mailer Error: ' . $mail->ErrorInfo;
					} else {
					$adminmobile='9105891059';
					if($mobile_num==$damobile)
					{
						sendsms($prodlist,$orderid,$trans_amount,$mobile_num);	
					}
					else
					{
						sendsms($prodlist,$orderid,$trans_amount,$mobile_num);	
						sendsms($prodlist,$orderid,$trans_amount,$damobile);	
					}
					sendsmsadmin($prodlist,$orderid,$trans_amount,$adminmobile);	
					header("Location: thankyou?r=$enc_regid&id=".base64_encode($orderid));
					}
					
				}
				else
				{
					if($trans_status!='CHARGED')
					{
						$pgnarration = "Transaction status is $trans_status";
						mysqli_query($dbhandle, "insert into trans_log (orderid, userid, datetime, description, entryby, status) values ('$orderid', '$userid', '$curdate', '$pgnarration','$customer_name','$trans_status')");
					}
					if($amount==$trans_amount)
					{
						$pgnarration = "Amount doesnt match $amount != $trans_amount";
						mysqli_query($dbhandle, "insert into trans_log (orderid, userid, datetime, description, entryby, status) values ('$orderid', '$userid', '$curdate', '$pgnarration', '$customer_name', 'Amount does not match')");
					}
					
					$pgnarration = "PG : Payment failed : OrderID=$orderid | Amount=$trans_amount";
					mysqli_query($dbhandle, "insert into trans_log (orderid, userid, datetime, description, entryby, status) values ('$orderid', '$userid', '$curdate', '$pgnarration', '$customer_name', 'payment failed')");				

					$msg=base64_encode("payment fail");
					header("Location: fail?r=$enc_regid&msg=$msg");
					die();
				}
			}
		}
		else
		{
			$pgnarration = "No response received from PG : OrderID=$orderid | Date=$curdate";
			mysqli_query($dbhandle, "insert into trans_log (orderid, userid, datetime, description, entryby, status) values ('$orderid', '$userid', '$curdate', '$pgnarration', '$customer_name', 'Amount doesnt match')");
			
			$msg=base64_encode("fail");
			header("Location: fail?r=$enc_regid&msg=$msg");
			die();
		}
	}
	else
	{
		$pgnarration = "Invalid RegistrationID found : OrderID=$orderid | Date=$curdate";
		mysqli_query($dbhandle, "insert into trans_log (orderid, userid, datetime, description, entryby, status) values ('$orderid', '$userid', '$curdate', '$pgnarration', '$customer_name', 'Amount doesnt match')");
		
		$msg=base64_encode("fail");
		header("Location: fail?r=$enc_regid&msg=$msg");
		die();
	}
}
?>