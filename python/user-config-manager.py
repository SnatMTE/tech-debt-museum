def add_setting(settings, key_value):
    """Add a new setting to the dictionary."""
    key, value = key_value
    key = key.lower()
    value = value.lower()
    
    if key in settings:
        return f"Setting '{key}' already exists! Cannot add a new setting with this name."
    
    settings[key] = value
    return f"Setting '{key}' added with value '{value}' successfully!"


def update_setting(settings, key_value):
    """Update an existing setting in the dictionary."""
    key, value = key_value
    key = key.lower()
    value = value.lower()
    
    if key not in settings:
        return f"Setting '{key}' does not exist! Cannot update a non-existing setting."
    
    settings[key] = value
    return f"Setting '{key}' updated to '{value}' successfully!"


def delete_setting(settings, key):
    """Delete a setting from the dictionary."""
    key = key.lower()
    
    if key not in settings:
        return "Setting not found!"
    
    del settings[key]
    return f"Setting '{key}' deleted successfully!"


def view_settings(settings):
    """View all settings in a formatted string."""
    if not settings:
        return "No settings available."
    
    result = "Current User Settings:\n"
    for k, v in settings.items():
        result += f"{k.capitalize()}: {v}\n"
    return result


# Create a dictionary to store user configuration preferences for testing
test_settings = {}
test_settings["theme"] = "light"
test_settings["language"] = "english"
test_settings["notifications"] = "enabled"
