<?php
class AntiSpam {
    private $db;
    
    public function __construct($database) {
        $this->db = $database;
    }
    
    // Check if email/IP is spam
    public function checkSpam($email, $ipAddress, $formType, $userAgent = null) {
        $spamScore = 0;
        $reasons = [];
        
        // Check 1: Rate limiting - same email multiple submissions
        $emailCount = $this->getSubmissionCount($email, 'email', 60); // last hour
        if ($emailCount > 3) {
            $spamScore += 0.4;
            $reasons[] = "Multiple submissions from same email";
        }
        
        // Check 2: Rate limiting - same IP multiple submissions
        $ipCount = $this->getSubmissionCount($ipAddress, 'ip', 60);
        if ($ipCount > 5) {
            $spamScore += 0.3;
            $reasons[] = "Multiple submissions from same IP";
        }
        
        // Check 3: Disposable email domains
        if ($this->isDisposableEmail($email)) {
            $spamScore += 0.5;
            $reasons[] = "Disposable email domain";
        }
        
        // Check 4: Suspicious patterns in email
        if ($this->hasSuspiciousPattern($email)) {
            $spamScore += 0.3;
            $reasons[] = "Suspicious email pattern";
        }
        
        // Check 5: Previous spam submissions
        if ($this->isPreviousSpammer($email, $ipAddress)) {
            $spamScore += 0.6;
            $reasons[] = "Previously marked as spam";
        }
        
        $isSpam = $spamScore >= 0.7;
        
        // Log the spam check
        $this->logSpamCheck($email, $ipAddress, $userAgent, $formType, $spamScore, $isSpam, implode(', ', $reasons));
        
        return [
            'is_spam' => $isSpam,
            'spam_score' => round($spamScore, 2),
            'reasons' => $reasons
        ];
    }
    
    private function getSubmissionCount($value, $type = 'email', $minutes = 60) {
        $query = "SELECT COUNT(*) as count FROM spam_logs 
                  WHERE " . ($type === 'email' ? "email" : "ip_address") . " = :value 
                  AND created_at >= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':value', $value);
        $stmt->bindParam(':minutes', $minutes);
        $stmt->execute();
        
        $result = $stmt->fetch();
        return $result ? $result['count'] : 0;
    }
    
    private function isDisposableEmail($email) {
        $disposableDomains = [
            'tempmail.com', 'throwaway.email', '10minutemail.com', 
            'guerrillamail.com', 'mailinator.com', 'trashmail.com',
            'yopmail.com', 'temp-mail.org', 'getnada.com'
        ];
        
        $domain = substr(strrchr($email, "@"), 1);
        return in_array(strtolower($domain), $disposableDomains);
    }
    
    private function hasSuspiciousPattern($email) {
        // Check for random character strings
        if (preg_match('/[a-z0-9]{20,}/', $email)) {
            return true;
        }
        
        // Check for excessive numbers
        if (preg_match('/[0-9]{8,}/', $email)) {
            return true;
        }
        
        return false;
    }
    
    private function isPreviousSpammer($email, $ipAddress) {
        $query = "SELECT COUNT(*) as count FROM spam_logs 
                  WHERE (email = :email OR ip_address = :ip) 
                  AND is_blocked = 1 
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':ip', $ipAddress);
        $stmt->execute();
        
        $result = $stmt->fetch();
        return $result && $result['count'] > 0;
    }
    
    private function logSpamCheck($email, $ipAddress, $userAgent, $formType, $spamScore, $isBlocked, $reason) {
        $query = "INSERT INTO spam_logs (email, ip_address, user_agent, form_type, spam_score, is_blocked, reason) 
                  VALUES (:email, :ip, :user_agent, :form_type, :spam_score, :is_blocked, :reason)";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':ip', $ipAddress);
        $stmt->bindParam(':user_agent', $userAgent);
        $stmt->bindParam(':form_type', $formType);
        $stmt->bindParam(':spam_score', $spamScore);
        $blocked = $isBlocked ? 1 : 0;
        $stmt->bindParam(':is_blocked', $blocked);
        $stmt->bindParam(':reason', $reason);
        $stmt->execute();
    }
    
    // Cleanup old spam logs (run periodically)
    public function cleanupOldLogs($days = 60) {
        $query = "DELETE FROM spam_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':days', $days);
        return $stmt->execute();
    }
}
