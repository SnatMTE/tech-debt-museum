class Category:
    def __init__(self, name):
        self.name = name
        self.ledger = []

    def deposit(self, amount, description=""):
        self.ledger.append({"amount": amount, "description": description})

    def withdraw(self, amount, description=""):
        if self.check_funds(amount):
            self.ledger.append({"amount": -amount, "description": description})
            return True
        return False

    def get_balance(self):
        return sum(item["amount"] for item in self.ledger)

    def transfer(self, amount, category):
        if self.check_funds(amount):
            self.withdraw(amount, f"Transfer to {category.name}")
            category.deposit(amount, f"Transfer from {self.name}")
            return True
        return False

    def check_funds(self, amount):
        return amount <= self.get_balance()

    def __str__(self):
        output = self.name.center(30, "*") + "\n"
        for item in self.ledger:
            desc = item["description"][:23]
            amt = f"{item['amount']:.2f}"
            output += desc.ljust(23) + amt.rjust(7) + "\n"
        output += f"Total: {self.get_balance():.2f}"
        return output


def create_spend_chart(categories):
    # Calculate total withdrawals per category
    spending = []
    for category in categories:
        total_withdrawals = sum(
            abs(item["amount"])
            for item in category.ledger
            if item["amount"] < 0
        )
        spending.append(total_withdrawals)

    total_spent = sum(spending)

    # Calculate percentages rounded down to nearest 10
    percentages = []
    for s in spending:
        if total_spent > 0:
            pct = int((s / total_spent) * 100)
            pct = (pct // 10) * 10
        else:
            pct = 0
        percentages.append(pct)

    # Build chart
    chart = "Percentage spent by category\n"

    for i in range(100, -1, -10):
        chart += str(i).rjust(3) + "| "
        for pct in percentages:
            if pct >= i:
                chart += "o  "
            else:
                chart += "   "
        chart += "\n"

    # Horizontal line
    chart += "    " + "-" * (len(categories) * 3 + 1) + "\n"

    # Category names vertically
    names = [category.name for category in categories]
    max_len = max(len(name) for name in names)
    for i in range(max_len):
        chart += "     "
        for name in names:
            if i < len(name):
                chart += name[i] + "  "
            else:
                chart += "   "
        if i < max_len - 1:
            chart += "\n"

    return chart
