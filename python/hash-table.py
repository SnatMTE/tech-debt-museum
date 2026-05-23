class HashTable:
    def __init__(self):
        self.collection = {}

    def hash(self, key):
        """Return the sum of Unicode values of each character in the key."""
        return sum(ord(char) for char in key)

    def add(self, key, value):
        """Add a key-value pair to the hash table using the hashed key."""
        hashed_key = self.hash(key)
        if hashed_key in self.collection:
            self.collection[hashed_key][key] = value
        else:
            self.collection[hashed_key] = {key: value}

    def remove(self, key):
        """Remove the key-value pair from the hash table if it exists."""
        hashed_key = self.hash(key)
        if hashed_key in self.collection and key in self.collection[hashed_key]:
            del self.collection[hashed_key][key]
            if not self.collection[hashed_key]:
                del self.collection[hashed_key]

    def lookup(self, key):
        """Return the value associated with the key, or None if not found."""
        hashed_key = self.hash(key)
        if hashed_key in self.collection and key in self.collection[hashed_key]:
            return self.collection[hashed_key][key]
        return None
