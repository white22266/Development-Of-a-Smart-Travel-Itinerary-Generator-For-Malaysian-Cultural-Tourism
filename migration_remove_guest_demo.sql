-- Remove guest demo support. The system is restricted to verified/login users only.
DROP TABLE IF EXISTS `guest_demo_logs`;
