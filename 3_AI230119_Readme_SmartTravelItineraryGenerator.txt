Project Name: Development of a Smart Travel Itinerary Generator for Malaysian Cultural Tourism
Matric No: AI230119
Student: Jianhao
System Type: Web-based (PHP + MySQL)

1) System URL
Example (local):
http://localhost/Development-Of-a-Smart-Travel-Itinerary-Generator-For-Malaysian-Cultural-Tourism/

2) How to run the system (Localhost / XAMPP)
A) Requirements
- XAMPP (Apache + MySQL)
- Browser: Chrome/Edge
- PHP version: 7.4+ and above.

B) Setup steps
1. Extract the zip folder and Copy the project folder into:

   C:\xampp\htdocs\Development-Of-a-Smart-Travel-Itinerary-Generator-For-Malaysian-Cultural-Tourism\

2. Start XAMPP Control Panel:
   - Start Apache
   - Start MySQL
   
3. Import database:
   - Open http://localhost/phpmyadmin
   - Create database: travel_itinerary_db
   - Import SQL: [ travel_itinerary_db.sql]

4. Update database connection:
   - File: config/db_connect.php
   - Ensure host/user/password are correct.

C) Access URL (Local)
http://localhost/FYP_1/Development-Of-a-Smart-Travel-Itinerary-Generator-For-Malaysian-Cultural-Tourism/
or just click the StartFYP.bat file.

3) Login Accounts (for examiners)
Traveller account:
- Email/Username: [FILL]
- Password: [FILL]

4）Admin account:
- Email/Username: [admin@gmail.com]
- Password: [123456]

5）Example login Traveller account：
-Email:ai230119@student.uthm.edu.my
-Password:[123456]

5) Key Modules to test
A) Traveller Preference Analyzer
- Create preference
- Select interests/states/trip days/budget

B) Smart Itinerary Generator
- Select preference
- Choose items per day and route strategy
- Generate itinerary

C) Itinerary View
- View day tabs + map route
- Route optimization view shows segment distance/time

D) Cost Estimation and Trip Summary
- View cost summary
- Export PDF (requires vendor folder if dompdf installed)

E) Cultural Guide Presentation
- Browse cultural places
- Traveller suggestion submission
- Admin approval workflow

6) Notes / Troubleshooting
- If PDF export shows “Dompdf not installed”, ensure vendor/ exists inside the project root.
- If maps not loading, check API key in config/api_keys.php (keys may be removed for submission).
- open php.ini in apache/config/php.ini modify for extension=openssl remove the semicolon";" to allow access for send email.