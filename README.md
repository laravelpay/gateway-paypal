# PayPal Rest API integration for Laravel Pay

This package provides a simple way to integrate PayPal Rest API payment gateway with Laravel Pay.

Before you can install this package, make sure you have the composer package `laravelpay/framework` installed. Learn more here https://github.com/laravelpay/framework

## Installation

Run this command inside your Laravel application

```
php artisan gateway:install laravelpay/gateway-paypal
```

## Setup

1. Go to https://developer.paypal.com/dashboard/applications/sandbox
2. Click "Create App" and create a new app, after its created open it

![image](https://github.com/user-attachments/assets/1294a4b7-b070-493e-99be-e17449250ba2)

3. Here you will find your Client ID and Client Secret, you will need this later

![image](https://github.com/user-attachments/assets/85087f0d-b3a7-4102-b091-bc516041985d)

4. run the command below to setup the gateway and fill in the information

```
php artisan gateway:setup paypal
```
